<?php

declare(strict_types=1);

namespace LumenWp;

/**
 * Chunked diagnose / rewrite of stale media URLs — production hardened.
 *
 * Drivers (in order of reliability on shared hosting):
 * 1) Tools page AJAX poll (1 tick / request)
 * 2) admin-post forms (start / tick / stop) without JS
 * 3) WP-Cron as optional background helper
 */
final class Url_Queue
{
	public const OPTION = 'lumen_wp_urls_job';
	public const LAST_ERRORS_OPTION = 'lumen_wp_urls_last_errors';
	public const CRON_HOOK = 'lumen_wp_urls_tick';
	public const LOCK = 'lumen_wp_urls_lock';
	public const ISSUES_MAX = 100;
	public const LOG_MAX = 40;
	public const ERRORS_MAX = 50;
	/** Soft lock TTL — short so a killed PHP worker cannot block forever. */
	public const LOCK_TTL = 45;
	/** Per-tick PHP budget (Hostinger-friendly). */
	public const TICK_TIME_LIMIT = 25;
	/** Auto-recover / allow restart when a running job is stale this long. */
	public const STALE_SECONDS = 120;

	public function register(): void
	{
		add_action(self::CRON_HOOK, [$this, 'tick']);
		add_action('wp_ajax_lumen_wp_urls_start', [$this, 'ajax_start']);
		add_action('wp_ajax_lumen_wp_urls_stop', [$this, 'ajax_stop']);
		add_action('wp_ajax_lumen_wp_urls_status', [$this, 'ajax_status']);
		add_action('wp_ajax_lumen_wp_urls_force_tick', [$this, 'ajax_force_tick']);
		add_action('admin_post_lumen_wp_urls_start', [$this, 'handle_post_start']);
		add_action('admin_post_lumen_wp_urls_tick', [$this, 'handle_post_tick']);
		add_action('admin_post_lumen_wp_urls_stop', [$this, 'handle_post_stop']);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array
	{
		return [
			'status'         => 'idle',
			'mode'           => 'diagnose',
			'force_full'     => false,
			'cursor'         => 0,
			'queue_ids'      => [],
			'queue_index'    => 0,
			'total_estimate' => 0,
			'processed'      => 0,
			'issues_found'   => 0,
			'attachments'    => 0,
			'replacements'   => 0,
			'posts'          => 0,
			'metas'          => 0,
			'options'        => 0,
			'css_files'      => 0,
			'err'            => 0,
			'last_message'   => '',
			'last_error'     => '',
			'started_at'     => '',
			'updated_at'     => '',
			'last_tick_at'   => '',
			'user_id'        => 0,
			'plugin_version' => defined('LUMEN_WP_VERSION') ? LUMEN_WP_VERSION : '',
			'log'            => [],
			'issues'         => [],
			'errors'         => [],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function job(): array
	{
		$stored = get_option(self::OPTION, []);
		if (! is_array($stored)) {
			$stored = [];
		}
		$job = array_merge(self::defaults(), $stored);
		if (! is_array($job['log'] ?? null)) {
			$job['log'] = [];
		}
		if (! is_array($job['issues'] ?? null)) {
			$job['issues'] = [];
		}
		if (! is_array($job['errors'] ?? null)) {
			$job['errors'] = [];
		}
		if (! is_array($job['queue_ids'] ?? null)) {
			$job['queue_ids'] = [];
		}
		$job['force_full']  = ! empty($job['force_full']);
		$job['queue_index'] = max(0, (int) ($job['queue_index'] ?? 0));

		return $job;
	}

	/**
	 * @param array<string, mixed> $job
	 */
	public static function save(array $job): void
	{
		$job['updated_at'] = gmdate('c');
		update_option(self::OPTION, $job, false);
	}

	/**
	 * Errors from the last finished / stopped job (survives job reset).
	 *
	 * @return list<array{id: int, message: string, at: string}>
	 */
	public static function last_errors(): array
	{
		$stored = get_option(self::LAST_ERRORS_OPTION, []);
		if (! is_array($stored)) {
			return [];
		}
		$out = [];
		foreach ($stored as $row) {
			if (! is_array($row)) {
				continue;
			}
			$id = (int) ($row['id'] ?? 0);
			$out[] = [
				'id'      => $id,
				'message' => (string) ($row['message'] ?? ''),
				'at'      => (string) ($row['at'] ?? ''),
			];
			if (count($out) >= self::ERRORS_MAX) {
				break;
			}
		}

		return $out;
	}

	/**
	 * @param list<array{id?: int, message?: string, at?: string}> $errors
	 */
	public static function save_last_errors(array $errors): void
	{
		$normalized = [];
		foreach ($errors as $row) {
			if (! is_array($row)) {
				continue;
			}
			$normalized[] = [
				'id'      => (int) ($row['id'] ?? 0),
				'message' => (string) ($row['message'] ?? ''),
				'at'      => (string) ($row['at'] ?? ''),
			];
			if (count($normalized) >= self::ERRORS_MAX) {
				break;
			}
		}
		update_option(self::LAST_ERRORS_OPTION, $normalized, false);
	}

	/**
	 * Unique positive attachment IDs from last_errors (stable order of first appearance).
	 *
	 * @return list<int>
	 */
	public static function last_error_ids(): array
	{
		$ids  = [];
		$seen = [];
		foreach (self::last_errors() as $row) {
			$id = (int) ($row['id'] ?? 0);
			if ($id <= 0 || isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;
			$ids[]     = $id;
		}

		return $ids;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function health(): array
	{
		$job           = self::job();
		$status        = (string) ($job['status'] ?? 'idle');
		$next          = wp_next_scheduled(self::CRON_HOOK);
		$cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
		$locked        = (bool) get_transient(self::LOCK);
		$last_tick     = (string) ($job['last_tick_at'] ?? '');
		$stale         = false;
		$stale_secs    = 0;

		if ($status === 'running') {
			if (! $next && ! $locked) {
				$stale = true;
			}
			$ref = $last_tick !== '' ? $last_tick : (string) ($job['started_at'] ?? '');
			if ($ref !== '') {
				$ts = strtotime($ref);
				if ($ts !== false) {
					$stale_secs = time() - $ts;
					if ($stale_secs > 45) {
						$stale = true;
					}
				}
			}
		}

		return [
			'ok'             => ! $cron_disabled && ! $stale,
			'cron_disabled'  => $cron_disabled,
			'next_scheduled' => $next ? (int) $next : null,
			'last_tick_at'   => $last_tick,
			'locked'         => $locked,
			'job_status'     => $status,
			'stale'          => $stale,
			'stale_seconds'  => $stale_secs,
			'hook'           => self::CRON_HOOK,
			'plugin_version' => defined('LUMEN_WP_VERSION') ? LUMEN_WP_VERSION : '',
		];
	}

	/**
	 * If a running job has been idle too long, mark recoverable and clear lock.
	 */
	public static function maybe_recover_stale(): void
	{
		$job = self::job();
		if (($job['status'] ?? '') !== 'running') {
			return;
		}
		$ref = (string) (($job['last_tick_at'] ?? '') !== '' ? $job['last_tick_at'] : ($job['started_at'] ?? ''));
		if ($ref === '') {
			return;
		}
		$ts = strtotime($ref);
		if ($ts === false || (time() - $ts) < self::STALE_SECONDS) {
			return;
		}
		delete_transient(self::LOCK);
		$job['last_error'] = sprintf(
			/* translators: %d: seconds */
			__('Job bloqué depuis %d s — verrou libéré. Cliquez « Avancer maintenant » ou relancez.', 'lumen-wp'),
			time() - $ts
		);
		$job['last_message'] = $job['last_error'];
		$job['log']          = self::push_log_static($job['log'] ?? [], $job['last_message']);
		self::save($job);
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function start_job(string $mode, bool $force_restart = false, bool $force_full = false)
	{
		$mode = sanitize_key($mode);
		if (! in_array($mode, ['diagnose', 'rewrite', 'retry'], true)) {
			return new \WP_Error('lumen_urls_mode', __('Mode invalide.', 'lumen-wp'));
		}

		self::maybe_recover_stale();
		$current = self::job();
		if (($current['status'] ?? '') === 'running') {
			$ref = (string) (($current['last_tick_at'] ?? '') !== '' ? $current['last_tick_at'] : ($current['started_at'] ?? ''));
			$ts  = $ref !== '' ? strtotime($ref) : false;
			$very_stale = $ts !== false && (time() - $ts) >= self::STALE_SECONDS;
			if (! $force_restart && ! $very_stale) {
				return new \WP_Error(
					'lumen_urls_busy',
					__('Un scan URLs est déjà en cours. Utilisez « Avancer maintenant », « Arrêter », ou attendez la reprise auto.', 'lumen-wp')
				);
			}
			$this->stop_job(__('Ancien job remplacé.', 'lumen-wp'), false);
		}

		$queue_ids = [];
		if ($mode === 'retry') {
			$queue_ids = self::last_error_ids();
			if ($queue_ids === []) {
				return new \WP_Error(
					'lumen_urls_no_errors',
					__('Aucune erreur à réessayer. Lancez d’abord un diagnostic ou une réécriture.', 'lumen-wp')
				);
			}
		}

		$user = wp_get_current_user();
		$job  = self::defaults();
		$job['status']         = 'running';
		$job['mode']           = $mode;
		$job['force_full']     = $mode !== 'retry' && $force_full;
		$job['cursor']         = 0;
		$job['queue_ids']      = $queue_ids;
		$job['queue_index']    = 0;
		$job['total_estimate'] = $mode === 'retry' ? count($queue_ids) : 0;
		$job['started_at']     = gmdate('c');
		$job['last_tick_at']   = gmdate('c');
		$job['user_id']        = (int) $user->ID;
		$job['plugin_version'] = defined('LUMEN_WP_VERSION') ? LUMEN_WP_VERSION : '';

		if ($mode === 'rewrite') {
			$job['last_message'] = ! empty($job['force_full'])
				? __('Réécriture complète démarrée — laissez cette page ouverte.', 'lumen-wp')
				: __('Réécriture démarrée (skip déjà propres) — laissez cette page ouverte.', 'lumen-wp');
		} elseif ($mode === 'retry') {
			$job['last_message'] = sprintf(
				/* translators: %d: error count */
				__('Réessai de %d média(s) en erreur — laissez cette page ouverte.', 'lumen-wp'),
				count($queue_ids)
			);
		} else {
			$job['last_message'] = ! empty($job['force_full'])
				? __('Diagnostic complet démarré — laissez cette page ouverte.', 'lumen-wp')
				: __('Diagnostic démarré (skip déjà propres) — laissez cette page ouverte.', 'lumen-wp');
		}

		$job['log'] = [$this->log_line($job['last_message'])];
		self::save($job);

		$this->schedule_soon();
		$this->spawn();

		return self::job();
	}

	public function ajax_start(): void
	{
		$this->guard_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification
		$mode = isset($_POST['mode']) ? (string) wp_unslash($_POST['mode']) : 'diagnose';
		// phpcs:ignore WordPress.Security.NonceVerification
		$force = ! empty($_POST['force_restart']);
		// phpcs:ignore WordPress.Security.NonceVerification
		$force_full = ! empty($_POST['force_full']);
		$job        = $this->start_job($mode, $force, $force_full);
		if (is_wp_error($job)) {
			$code = $job->get_error_code() === 'lumen_urls_busy' ? 409 : 400;
			wp_send_json_error(
				[
					'message'     => $job->get_error_message(),
					'job'         => self::job(),
					'health'      => self::health(),
					'last_errors' => self::last_errors(),
				],
				$code
			);
		}

		delete_transient(self::LOCK);
		$this->tick();

		wp_send_json_success($this->payload());
	}

	public function ajax_stop(): void
	{
		$this->guard_ajax();
		$this->stop_job(__('Arrêté.', 'lumen-wp'));
		wp_send_json_success($this->payload());
	}

	public function ajax_status(): void
	{
		$this->guard_ajax();
		self::maybe_recover_stale();
		$job = self::job();

		if (($job['status'] ?? '') === 'running') {
			delete_transient(self::LOCK);
			$this->tick();
			if ((self::job()['status'] ?? '') === 'running') {
				$this->schedule_soon();
				$this->spawn();
			}
		}

		wp_send_json_success($this->payload());
	}

	public function ajax_force_tick(): void
	{
		$this->guard_ajax();
		self::maybe_recover_stale();
		$job = self::job();
		if (($job['status'] ?? '') !== 'running') {
			wp_send_json_error(
				[
					'message' => __('Aucun scan URLs en cours.', 'lumen-wp'),
					'job'     => $job,
					'health'  => self::health(),
				],
				400
			);
		}

		delete_transient(self::LOCK);
		$this->tick();
		if ((self::job()['status'] ?? '') === 'running') {
			$this->schedule_soon();
			$this->spawn();
		}

		wp_send_json_success($this->payload());
	}

	public function handle_post_start(): void
	{
		$this->guard_post();
		// phpcs:ignore WordPress.Security.NonceVerification
		$mode = isset($_POST['mode']) ? (string) wp_unslash($_POST['mode']) : 'diagnose';
		// phpcs:ignore WordPress.Security.NonceVerification
		$force = ! empty($_POST['force_restart']);
		// phpcs:ignore WordPress.Security.NonceVerification
		$force_full = ! empty($_POST['force_full']);
		$job        = $this->start_job($mode, $force, $force_full);
		if (! is_wp_error($job)) {
			delete_transient(self::LOCK);
			$this->tick();
		} else {
			$cur                 = self::job();
			$cur['last_error']   = $job->get_error_message();
			$cur['last_message'] = $cur['last_error'];
			self::save($cur);
		}
		$this->redirect_tools();
	}

	public function handle_post_tick(): void
	{
		$this->guard_post();
		self::maybe_recover_stale();
		$job = self::job();
		if (($job['status'] ?? '') === 'running') {
			delete_transient(self::LOCK);
			$this->tick();
		}
		$this->redirect_tools();
	}

	public function handle_post_stop(): void
	{
		$this->guard_post();
		$this->stop_job(__('Arrêté.', 'lumen-wp'));
		$this->redirect_tools();
	}

	public function tick(): void
	{
		if (get_transient(self::LOCK)) {
			return;
		}
		set_transient(self::LOCK, 1, self::LOCK_TTL);
		@set_time_limit(self::TICK_TIME_LIMIT); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		try {
			$job                 = self::job();
			$job['last_tick_at'] = gmdate('c');
			self::save($job);

			if (($job['status'] ?? '') !== 'running') {
				return;
			}

			$mode       = (string) ($job['mode'] ?? 'diagnose');
			$force_full = ! empty($job['force_full']);
			$is_retry   = $mode === 'retry';
			$work_mode  = $is_retry ? 'rewrite' : $mode;

			if ($is_retry) {
				$queue = is_array($job['queue_ids'] ?? null) ? $job['queue_ids'] : [];
				$idx   = (int) ($job['queue_index'] ?? 0);
				if ($idx >= count($queue)) {
					$this->finalize($job, $mode);

					return;
				}
				$next = (int) $queue[ $idx ];
				if ($next <= 0) {
					$job['queue_index'] = $idx + 1;
					$job['processed']   = (int) $job['processed'] + 1;
					$job['last_message'] = __('ID invalide ignoré', 'lumen-wp');
					$job['log']          = $this->push_log($job['log'] ?? [], $job['last_message']);
					self::save($job);
					$this->schedule_soon();

					return;
				}
			} else {
				$next = Content_Url_Rewriter::next_candidate_id((int) ($job['cursor'] ?? 0), $force_full);
				if ($next <= 0) {
					$this->finalize($job, $mode);

					return;
				}
			}

			$job['total_estimate'] = max(
				(int) ($job['total_estimate'] ?? 0),
				(int) ($job['processed'] ?? 0) + 1
			);

			try {
				$pairs = Content_Url_Rewriter::pairs_for_attachment($next);
			} catch (\Throwable $e) {
				$this->record_error($job, $next, $e->getMessage());
				$this->advance_cursor($job, $next, $is_retry);
				$job['processed']    = (int) $job['processed'] + 1;
				$job['err']          = (int) $job['err'] + 1;
				$job['last_message'] = '#' . $next . ' — ' . __('erreur (ignoré)', 'lumen-wp');
				$job['log']          = $this->push_log($job['log'] ?? [], $job['last_message']);
				self::save($job);
				$this->schedule_soon();

				return;
			}

			$line = '#' . $next;

			if ($pairs === []) {
				Content_Url_Rewriter::mark_urls_clean($next);
				$this->advance_cursor($job, $next, $is_retry);
				$job['processed']    = (int) $job['processed'] + 1;
				$job['last_message'] = $line . ' — ' . __('aucun chemin obsolète', 'lumen-wp');
				$job['log']          = $this->push_log($job['log'] ?? [], $job['last_message']);
				self::save($job);
				$this->schedule_soon();

				return;
			}

			$title = (string) ($pairs[0]['title'] ?? ('#' . $next));
			$line  = '#' . $next . ' — ' . $title;

			$ok_for_clean = false;
			try {
				if ($work_mode === 'rewrite') {
					$att_hits  = 0;
					$tick_repl = 0;
					$css_n     = 0;
					foreach ($pairs as $pair) {
						$r = Content_Url_Rewriter::after_attachment_path_change(
							(int) $pair['id'],
							(string) $pair['old_abs'],
							(string) $pair['new_abs'],
							true,
							false
						);
						$job['posts']        = (int) $job['posts'] + (int) ($r['posts'] ?? 0);
						$job['metas']        = (int) $job['metas'] + (int) ($r['metas'] ?? 0);
						$job['options']      = (int) $job['options'] + (int) ($r['options'] ?? 0);
						$job['replacements'] = (int) $job['replacements'] + (int) ($r['replacements'] ?? 0);
						$tick_repl          += (int) ($r['replacements'] ?? 0);
						if ((int) ($r['replacements'] ?? 0) > 0) {
							$att_hits++;
						}
						$css_n += Content_Url_Rewriter::rewrite_elementor_css_files(
							(string) $pair['old_abs'],
							(string) $pair['new_abs']
						);
					}
					$job['css_files'] = (int) $job['css_files'] + $css_n;
					if ($att_hits > 0) {
						$job['attachments'] = (int) $job['attachments'] + 1;
					}
					$job['last_message'] = $line . ' — ' . sprintf(
						/* translators: 1: replacements 2: css files */
						__('%1$d remplaç. / %2$d CSS', 'lumen-wp'),
						$tick_repl,
						$css_n
					);
					$job['last_error'] = '';
					$ok_for_clean      = true;
				} else {
					$found = 0;
					foreach ($pairs as $pair) {
						$issue = Content_Url_Rewriter::diagnose_pair($pair);
						if ($issue === null) {
							continue;
						}
						$found++;
						$job['issues_found'] = (int) $job['issues_found'] + 1;
						$issues              = is_array($job['issues'] ?? null) ? $job['issues'] : [];
						if (count($issues) < self::ISSUES_MAX) {
							$issues[]      = $issue;
							$job['issues'] = $issues;
						}
					}
					$job['last_message'] = $line . ' — ' . sprintf(
						/* translators: %d: issues */
						__('%d URL(s) obsolète(s)', 'lumen-wp'),
						$found
					);
					$job['last_error'] = '';
				}
			} catch (\Throwable $e) {
				$this->record_error($job, $next, $e->getMessage());
				$job['err']          = (int) $job['err'] + 1;
				$job['last_message'] = $line . ' — ' . __('erreur (ignoré)', 'lumen-wp');
				$ok_for_clean        = false;
			}

			if ($ok_for_clean) {
				Content_Url_Rewriter::mark_urls_clean($next);
			}

			$this->advance_cursor($job, $next, $is_retry);
			$job['processed'] = (int) $job['processed'] + 1;
			$job['log']       = $this->push_log($job['log'] ?? [], $job['last_message']);
			self::save($job);

			if (($job['status'] ?? '') === 'running') {
				$this->schedule_soon();
			}
		} catch (\Throwable $e) {
			$job = self::job();
			$this->record_error($job, (int) ($job['cursor'] ?? 0), $e->getMessage());
			$job['last_message'] = __('Erreur tick — reprise au prochain passage.', 'lumen-wp');
			$job['log']          = $this->push_log($job['log'] ?? [], $job['last_message']);
			self::save($job);
		} finally {
			delete_transient(self::LOCK);
		}
	}

	/**
	 * @return array{job: array<string, mixed>, health: array<string, mixed>, last_errors: list<array{id: int, message: string, at: string}>}
	 */
	private function payload(): array
	{
		return [
			'job'         => self::job(),
			'health'      => self::health(),
			'last_errors' => self::last_errors(),
		];
	}

	/**
	 * @param array<string, mixed> $job
	 */
	private function advance_cursor(array &$job, int $next, bool $is_retry): void
	{
		$job['cursor'] = $next;
		if ($is_retry) {
			$job['queue_index'] = (int) ($job['queue_index'] ?? 0) + 1;
		}
	}

	/**
	 * @param array<string, mixed> $job
	 */
	private function persist_last_errors_from_job(array $job): void
	{
		$errors = is_array($job['errors'] ?? null) ? $job['errors'] : [];
		self::save_last_errors($errors);
	}

	private function stop_job(string $message, bool $persist_errors = true): void
	{
		$job                 = self::job();
		$job['status']       = 'idle';
		$job['last_message'] = $message;
		$job['log']          = $this->push_log($job['log'] ?? [], $job['last_message']);
		if ($persist_errors) {
			$this->persist_last_errors_from_job($job);
		}
		self::save($job);
		wp_clear_scheduled_hook(self::CRON_HOOK);
		delete_transient(self::LOCK);
	}

	/**
	 * @param array<string, mixed> $job
	 */
	private function finalize(array $job, string $mode): void
	{
		if ($mode === 'rewrite' || $mode === 'retry') {
			Content_Url_Rewriter::clear_elementor_cache();
			$job['last_message'] = sprintf(
				/* translators: 1: attachments 2: replacements 3: css 4: errors */
				__('Réécriture terminée — %1$d média(s), %2$d remplacement(s), %3$d CSS, %4$d erreur(s). Pensez à régénérer le CSS Elementor.', 'lumen-wp'),
				(int) ($job['attachments'] ?? 0),
				(int) ($job['replacements'] ?? 0),
				(int) ($job['css_files'] ?? 0),
				(int) ($job['err'] ?? 0)
			);
		} else {
			$job['last_message'] = sprintf(
				/* translators: 1: scanned 2: issues 3: errors */
				__('Diagnostic terminé — %1$d média(s) scanné(s), %2$d URL(s) obsolète(s), %3$d erreur(s).', 'lumen-wp'),
				(int) ($job['processed'] ?? 0),
				(int) ($job['issues_found'] ?? 0),
				(int) ($job['err'] ?? 0)
			);
		}

		$job['status']         = 'done';
		$job['total_estimate'] = max((int) ($job['total_estimate'] ?? 0), (int) ($job['processed'] ?? 0));
		$job['last_error']     = (int) ($job['err'] ?? 0) > 0
			? (string) ($job['last_error'] ?? '')
			: '';
		$job['log'] = $this->push_log($job['log'] ?? [], $job['last_message']);
		$this->persist_last_errors_from_job($job);
		self::save($job);
		wp_clear_scheduled_hook(self::CRON_HOOK);
	}

	/**
	 * @param array<string, mixed> $job
	 */
	private function record_error(array &$job, int $id, string $message): void
	{
		$message = trim($message);
		if ($message === '') {
			$message = __('Erreur inconnue', 'lumen-wp');
		}
		$job['last_error'] = ($id > 0 ? '#' . $id . ' — ' : '') . $message;
		$errors            = is_array($job['errors'] ?? null) ? $job['errors'] : [];
		array_unshift(
			$errors,
			[
				'id'      => $id,
				'message' => $message,
				'at'      => gmdate('c'),
			]
		);
		$job['errors'] = array_slice($errors, 0, self::ERRORS_MAX);
		$job['log']    = $this->push_log($job['log'] ?? [], '⚠ ' . $job['last_error']);
	}

	private function schedule_soon(): void
	{
		wp_clear_scheduled_hook(self::CRON_HOOK);
		wp_schedule_single_event(time() + 1, self::CRON_HOOK);
	}

	private function spawn(): void
	{
		if (function_exists('spawn_cron')) {
			spawn_cron(time());
		}
	}

	private function guard_ajax(): void
	{
		if (! current_user_can('upload_files')) {
			wp_send_json_error(['message' => __('Permission refusée.', 'lumen-wp')], 403);
		}
		check_ajax_referer('lumen_wp_admin', 'nonce');
	}

	private function guard_post(): void
	{
		if (! current_user_can('upload_files')) {
			wp_die(esc_html__('Permission refusée.', 'lumen-wp'), 403);
		}
		check_admin_referer('lumen_wp_urls');
	}

	private function redirect_tools(): void
	{
		wp_safe_redirect(admin_url('admin.php?page=lumen-wp-tools#lumen-wp-urls-broken'));
		exit;
	}

	/**
	 * @param list<string>|mixed $log
	 * @return list<string>
	 */
	private function push_log($log, string $line): array
	{
		return self::push_log_static($log, $line);
	}

	/**
	 * @param list<string>|mixed $log
	 * @return list<string>
	 */
	private static function push_log_static($log, string $line): array
	{
		if (! is_array($log)) {
			$log = [];
		}
		array_unshift($log, wp_date('H:i:s') . ' — ' . $line);

		return array_slice($log, 0, self::LOG_MAX);
	}

	private function log_line(string $message): string
	{
		return wp_date('H:i:s') . ' — ' . $message;
	}
}
