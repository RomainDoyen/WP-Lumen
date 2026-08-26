<?php

declare(strict_types=1);

namespace LumenWp;

final class Bulk_Queue
{
	public const OPTION = 'lumen_wp_bulk_job';
	public const HISTORY_OPTION = 'lumen_wp_bulk_history';
	public const HISTORY_MAX = 50;
	public const ERRORS_MAX = 200;
	public const CRON_HOOK = 'lumen_wp_bulk_tick';
	public const LOCK = 'lumen_wp_bulk_lock';

	public function register(): void
	{
		add_action(self::CRON_HOOK, [$this, 'tick']);
		add_action('wp_ajax_lumen_wp_bulk_start', [$this, 'ajax_start']);
		add_action('wp_ajax_lumen_wp_bulk_pause', [$this, 'ajax_pause']);
		add_action('wp_ajax_lumen_wp_bulk_resume', [$this, 'ajax_resume']);
		add_action('wp_ajax_lumen_wp_bulk_stop', [$this, 'ajax_stop']);
		add_action('wp_ajax_lumen_wp_bulk_status', [$this, 'ajax_status']);
		add_action('wp_ajax_lumen_wp_bulk_force_tick', [$this, 'ajax_force_tick']);
		add_action('wp_ajax_lumen_wp_bulk_estimate', [$this, 'ajax_estimate']);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array
	{
		return [
			'status'         => 'idle',
			'force'          => false,
			'use_ai'         => false,
			'types'          => Media_Types::all_types(),
			'ai_provider'    => 'none',
			'ai_label'       => '',
			'cursor'         => 0,
			'total_estimate' => 0,
			'batch_size'     => 2,
			'batch_min'      => 1,
			'batch_max'      => 10,
			'tick_budget'    => 22,
			'processed'      => 0,
			'ok'             => 0,
			'err'            => 0,
			'last_message'   => '',
			'started_at'     => '',
			'updated_at'     => '',
			'last_tick_at'   => '',
			'user_id'        => 0,
			'user_name'      => '',
			'archived'       => false,
			'pause_reason'   => '',
			'log'            => [],
			'errors'         => [],
		];
	}

	/**
	 * @param mixed $errors
	 * @return list<array{id: int, title: string, message: string, edit_url: string}>
	 */
	public static function normalize_errors($errors): array
	{
		if (! is_array($errors)) {
			return [];
		}

		$out = [];
		foreach ($errors as $row) {
			if (is_string($row)) {
				$text = trim($row);
				if ($text === '') {
					continue;
				}
				$id = 0;
				$msg = $text;
				if (preg_match('/^#(\d+)\s*[—\-–]\s*(.*)$/u', $text, $m)) {
					$id  = (int) $m[1];
					$msg = trim((string) $m[2]);
				}
				$out[] = self::make_error_entry($id, $msg !== '' ? $msg : $text);
				continue;
			}

			if (! is_array($row)) {
				continue;
			}

			$id = (int) ($row['id'] ?? 0);
			$out[] = [
				'id'       => $id,
				'title'    => (string) ($row['title'] ?? ($id > 0 ? '#' . $id : __('Média', 'lumen-wp'))),
				'message'  => (string) ($row['message'] ?? ''),
				'edit_url' => (string) ($row['edit_url'] ?? ($id > 0 ? self::edit_url_for($id) : '')),
			];
		}

		return $out;
	}

	/**
	 * @return array{id: int, title: string, message: string, edit_url: string}
	 */
	public static function make_error_entry(int $attachment_id, string $message): array
	{
		$title = '';
		if ($attachment_id > 0) {
			$title = (string) get_the_title($attachment_id);
			if ($title === '') {
				$file = get_attached_file($attachment_id);
				$title = is_string($file) && $file !== '' ? basename($file) : '#' . $attachment_id;
			}
		}
		if ($title === '') {
			$title = __('Média', 'lumen-wp');
		}

		return [
			'id'       => $attachment_id,
			'title'    => $title,
			'message'  => $message,
			'edit_url' => $attachment_id > 0 ? self::edit_url_for($attachment_id) : '',
		];
	}

	public static function edit_url_for(int $attachment_id): string
	{
		$link = get_edit_post_link($attachment_id, 'raw');

		return is_string($link) && $link !== ''
			? $link
			: admin_url('post.php?post=' . $attachment_id . '&action=edit');
	}

	/**
	 * Last runs (newest first), for multi-admin visibility.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function history(): array
	{
		$stored = get_option(self::HISTORY_OPTION, []);
		if (! is_array($stored)) {
			return [];
		}

		$out = [];
		foreach ($stored as $row) {
			if (is_array($row) && ! empty($row['started_at'])) {
				$row['errors'] = self::normalize_errors($row['errors'] ?? []);
				$out[]         = $row;
			}
		}

		return array_slice($out, 0, self::HISTORY_MAX);
	}

	/**
	 * Cron / job health snapshot for Bulk + Tools UI.
	 *
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

		if ($status === 'running') {
			if (! $next && ! $locked) {
				$stale = true;
			}
			if ($last_tick !== '') {
				$ts = strtotime($last_tick);
				if ($ts !== false && (time() - $ts) > 300) {
					$stale = true;
				}
			} elseif (! empty($job['started_at'])) {
				$ts = strtotime((string) $job['started_at']);
				if ($ts !== false && (time() - $ts) > 180) {
					$stale = true;
				}
			}
		}

		$ok = ! $cron_disabled && ! $stale;

		return [
			'ok'             => $ok,
			'cron_disabled'  => $cron_disabled,
			'next_scheduled' => $next ? (int) $next : null,
			'last_tick_at'   => $last_tick,
			'locked'         => $locked,
			'job_status'     => $status,
			'stale'          => $stale,
			'hook'           => self::CRON_HOOK,
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
		$job['types']       = Media_Types::normalize_types($job['types'] ?? Media_Types::all_types());
		$job['errors']      = self::normalize_errors($job['errors'] ?? []);
		$job['batch_min']   = max(1, (int) ($job['batch_min'] ?? 1));
		$job['batch_max']   = max($job['batch_min'], (int) ($job['batch_max'] ?? 10));
		$job['batch_size']  = max($job['batch_min'], min($job['batch_max'], (int) ($job['batch_size'] ?? 2)));
		$job['tick_budget'] = max(8, min(60, (int) ($job['tick_budget'] ?? 22)));

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

	public function ajax_estimate(): void
	{
		$this->guard();

		$force  = ! empty($_POST['force']); // phpcs:ignore WordPress.Security.NonceVerification
		$use_ai = ! empty($_POST['use_ai']) || ! empty($_POST['use_mistral']); // phpcs:ignore WordPress.Security.NonceVerification
		// phpcs:ignore WordPress.Security.NonceVerification
		$raw_types = isset($_POST['types']) ? wp_unslash($_POST['types']) : Media_Types::all_types();
		if (is_string($raw_types)) {
			$raw_types = array_filter(array_map('trim', explode(',', $raw_types)));
		}
		$types = Media_Types::normalize_types($raw_types);
		if ($types === []) {
			wp_send_json_error(['message' => __('Sélectionnez au moins un type de média.', 'lumen-wp')], 400);
		}

		$pending = $this->count_pending($force, $types);
		$ai_types = array_values(
			array_filter(
				$types,
				static function (string $t): bool {
					return in_array(
						$t,
						[Media_Types::KIND_IMAGE, Media_Types::KIND_PDF, Media_Types::KIND_VIDEO],
						true
					);
				}
			)
		);
		$calls = 0;
		if ($use_ai && Vision_Ai::is_configured() && $ai_types !== []) {
			$calls = $this->count_pending($force, $ai_types);
		}

		$usage    = Vision_Ai::usage();
		$used     = (int) ($usage['calls_month'] ?? 0);
		$budget   = (int) (Plugin::instance()->settings()['ai_budget_month'] ?? 0);
		$remaining = $budget > 0 ? max(0, $budget - $used) : -1; // -1 = unlimited
		$within   = $budget <= 0 || $calls <= $remaining;

		if (! $use_ai) {
			$message = sprintf(
				/* translators: %d: pending media */
				__('Sans IA — ~%d média(s) à traiter.', 'lumen-wp'),
				$pending
			);
		} elseif (! Vision_Ai::is_configured()) {
			$message = __('IA cochée mais non configurée — aucun appel estimé.', 'lumen-wp');
		} elseif ($budget > 0) {
			$message = sprintf(
				/* translators: 1: estimated calls 2: remaining 3: budget */
				__('~%1$d appel(s) IA estimé(s) · reste %2$d / %3$d ce mois.', 'lumen-wp'),
				$calls,
				$remaining,
				$budget
			);
			if (! $within) {
				$message .= ' ' . __('Attention : au-delà du budget Lumen (fallback local).', 'lumen-wp');
			}
		} else {
			$message = sprintf(
				/* translators: %d: estimated calls */
				__('~%d appel(s) IA estimé(s) · budget Lumen illimité.', 'lumen-wp'),
				$calls
			);
		}

		wp_send_json_success(
			[
				'pending'        => $pending,
				'calls'          => $calls,
				'used_month'     => $used,
				'budget'         => $budget,
				'remaining'      => $remaining,
				'within_budget'  => $within,
				'budget_reached' => Vision_Ai::budget_reached(),
				'message'        => $message,
				'require_validation' => ! empty(Plugin::instance()->settings()['ai_require_validation']),
			]
		);
	}

	public function ajax_start(): void
	{
		try {
			$this->guard();

			$force  = ! empty($_POST['force']); // phpcs:ignore WordPress.Security.NonceVerification
			$use_ai = ! empty($_POST['use_ai']) || ! empty($_POST['use_mistral']); // phpcs:ignore WordPress.Security.NonceVerification
			// phpcs:ignore WordPress.Security.NonceVerification
			$raw_types = isset($_POST['types']) ? wp_unslash($_POST['types']) : Media_Types::all_types();
			if (is_string($raw_types)) {
				$raw_types = array_filter(array_map('trim', explode(',', $raw_types)));
			}
			$types = Media_Types::normalize_types($raw_types);
			if ($types === []) {
				wp_send_json_error(['message' => __('Sélectionnez au moins un type de média.', 'lumen-wp')], 400);
			}

			$current = self::job();
			if (($current['status'] ?? '') === 'running') {
				wp_send_json_error(['message' => __('Un traitement est déjà en cours.', 'lumen-wp')], 409);
			}

			// Archive un run précédent non encore historisé (ex. terminé non pollé).
			if (! empty($current['started_at']) && empty($current['archived'])) {
				self::push_history($current, ($current['status'] ?? '') === 'done' ? 'done' : 'stopped');
			}

			self::recover_stale_processing(60);

			$user = wp_get_current_user();
			$job  = self::defaults();
			$job['status']         = 'running';
			$job['force']          = $force;
			$job['use_ai']         = $use_ai;
			$job['types']          = $types;
			$job['ai_provider']    = $use_ai ? Vision_Ai::active_provider() : 'none';
			$job['ai_label']       = $use_ai ? Vision_Ai::provider_label(Vision_Ai::active_provider()) : '';
			$job['cursor']         = 0;
			$job['total_estimate'] = 0; // Filled asynchronously (no heavy COUNT at start).
			$job['batch_size']     = $use_ai ? 1 : 2;
			$job['started_at']     = gmdate('c');
			$job['last_tick_at']   = gmdate('c');
			$job['user_id']        = (int) $user->ID;
			$job['user_name']      = (string) ($user->display_name !== '' ? $user->display_name : $user->user_login);
			$job['last_message']   = __('Démarré — estimation du total en arrière-plan…', 'lumen-wp');
			$job['log']            = [$this->log_line($job['last_message'], true)];
			self::save($job);

			As_Bridge::enqueue_count_estimate('bulk');
			$this->schedule_soon();
			$this->spawn();
			// Ne pas drainer AS ici : COUNT sur grosse médiathèque + tick IA = timeout admin-ajax.

			wp_send_json_success([
				'job'     => self::job(),
				'history' => self::history(),
				'health'  => self::health(),
			]);
		} catch (\Throwable $e) {
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %s: error message */
						__('Impossible de démarrer : %s', 'lumen-wp'),
						$e->getMessage()
					),
				],
				500
			);
		}
	}

	public function ajax_pause(): void
	{
		$this->guard();
		$job = self::job();
		if (($job['status'] ?? '') !== 'running') {
			wp_send_json_error(['message' => __('Aucun traitement en cours.', 'lumen-wp')], 400);
		}
		$job['status'] = 'paused';
		$job['pause_reason'] = 'manual';
		$job['last_message'] = __('En pause.', 'lumen-wp');
		$job['log'] = $this->push_log($job['log'] ?? [], $job['last_message'], true);
		self::save($job);
		wp_clear_scheduled_hook(self::CRON_HOOK);
		As_Bridge::cancel_bulk();
		wp_send_json_success(['job' => self::job()]);
	}

	public function ajax_resume(): void
	{
		$this->guard();
		$job = self::job();
		if (($job['status'] ?? '') !== 'paused') {
			wp_send_json_error(['message' => __('Aucun traitement en pause.', 'lumen-wp')], 400);
		}
		$job['status'] = 'running';
		$job['pause_reason'] = '';
		$job['last_message'] = __('Repris.', 'lumen-wp');
		$job['log'] = $this->push_log($job['log'] ?? [], $job['last_message'], true);
		self::save($job);
		$this->schedule_soon();
		$this->spawn();
		wp_send_json_success(['job' => self::job()]);
	}

	public function ajax_stop(): void
	{
		$this->guard();
		$job = self::job();
		self::push_history($job, 'stopped');
		$job['status'] = 'idle';
		$job['archived'] = true;
		$job['last_message'] = __('Arrêté.', 'lumen-wp');
		$job['log'] = $this->push_log($job['log'] ?? [], $job['last_message'], true);
		self::save($job);
		wp_clear_scheduled_hook(self::CRON_HOOK);
		As_Bridge::cancel_bulk();
		delete_transient(self::LOCK);
		wp_send_json_success([
			'job'     => self::job(),
			'history' => self::history(),
		]);
	}

	public function ajax_status(): void
	{
		$this->guard();
		$job = self::job();

		if (($job['status'] ?? '') === 'running') {
			// Budget court : éviter de bloquer le poll 2s sur un COUNT AS / tick IA.
			As_Bridge::run_pending(3);
			$job = self::job();
			if (($job['status'] ?? '') === 'running') {
				$this->schedule_soon();
				$this->spawn();
			}
		}

		wp_send_json_success([
			'job'            => self::job(),
			'history'        => self::history(),
			'ai'             => [
				'provider'       => Vision_Ai::active_provider(),
				'provider_label' => Vision_Ai::provider_label(Vision_Ai::active_provider()),
				'configured'     => Vision_Ai::is_configured(),
				'usage'          => Vision_Ai::usage(),
				'budget'         => (int) (Plugin::instance()->settings()['ai_budget_month'] ?? 0),
				'budget_reached' => Vision_Ai::budget_reached(),
			],
			'cron_disabled'  => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
			'next_scheduled' => wp_next_scheduled(self::CRON_HOOK),
			'health'         => self::health(),
			'as_available'   => As_Bridge::available(),
		]);
	}

	public function ajax_force_tick(): void
	{
		$this->guard();
		try {
			$job = self::job();
			if (($job['status'] ?? '') !== 'running') {
				wp_send_json_error(['message' => __('Aucun traitement en cours à relancer.', 'lumen-wp')], 400);
			}

			delete_transient(self::LOCK);
			self::recover_stale_processing(60);
			// Un seul tick synchrone — pas de run_pending avant (évite double travail + timeout).
			$this->tick();
			if ((self::job()['status'] ?? '') === 'running') {
				$this->schedule_soon();
				$this->spawn();
			}

			wp_send_json_success([
				'job'     => self::job(),
				'health'  => self::health(),
				'history' => self::history(),
			]);
		} catch (\Throwable $e) {
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %s: error message */
						__('Échec du tick : %s', 'lumen-wp'),
						$e->getMessage()
					),
				],
				500
			);
		}
	}

	public function tick(): void
	{
		$job = self::job();
		if (get_transient(self::LOCK)) {
			// Lock orphelin après un timeout PHP : débloquer si le dernier tick est vieux.
			$last = (string) ($job['last_tick_at'] ?? '');
			$ts   = $last !== '' ? strtotime($last) : false;
			if ($ts !== false && (time() - $ts) > 90) {
				delete_transient(self::LOCK);
			} else {
				return;
			}
		}

		self::recover_stale_processing();

		set_transient(self::LOCK, 1, 45);
		$tick_budget  = (int) ($job['tick_budget'] ?? 22);
		@set_time_limit($tick_budget + 15); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		Optimizer::boost_imagick_limits();

		$started_at        = microtime(true);
		$deadline          = $started_at + $tick_budget;
		$processed_in_tick = 0;
		$hit_budget        = false;

		try {
			$job                 = self::job();
			$job['last_tick_at'] = gmdate('c');
			self::save($job);

			if (($job['status'] ?? '') !== 'running') {
				return;
			}

			$force      = ! empty($job['force']);
			$use_ai     = ! empty($job['use_ai']);
			$types      = Media_Types::normalize_types($job['types'] ?? Media_Types::all_types());
			$batch_size = (int) ($job['batch_size'] ?? 2);

			for ($i = 0; $i < $batch_size; $i++) {
				$remaining = $deadline - microtime(true);
				if ($remaining < 5) {
					$hit_budget = true;
					break;
				}

				$job = self::job();
				if (($job['status'] ?? '') !== 'running') {
					return;
				}

				$next = $this->next_id((int) $job['cursor'], $force, $types);
				if ($next <= 0) {
					$job['status'] = 'done';
					$job['last_message'] = sprintf(
						/* translators: 1: ok count, 2: error count */
						__('Terminé — %1$d OK / %2$d erreur(s).', 'lumen-wp'),
						(int) $job['ok'],
						(int) $job['err']
					);
					$job['log'] = $this->push_log($job['log'] ?? [], $job['last_message'], true);
					self::push_history($job, 'done');
					$job['archived'] = true;
					self::save($job);
					wp_clear_scheduled_hook(self::CRON_HOOK);
					As_Bridge::cancel_bulk();

					return;
				}

				// L’IA Vision (timeout HTTP) doit tenir dans le budget restant du tick.
				$ai_this = $use_ai && $remaining >= 18;

				$result = (new Hooks())->process($next, $force, $ai_this);
				$ok     = ! empty($result['ok']);
				$msg    = (string) ($result['message'] ?? ($result['status'] ?? ''));
				if ($msg === '') {
					$msg = $ok ? 'ok' : 'error';
				}
				if ($use_ai && ! $ai_this && $ok) {
					$msg .= ' — ' . __('IA reportée (budget tick) — SEO local.', 'lumen-wp');
				}
				$entry = self::make_error_entry($next, $msg);
				$line  = '#' . $next . ' — ' . $entry['title'] . ' — ' . $msg;

				$job                 = self::job();
				$job['cursor']       = $next;
				$job['processed']    = (int) $job['processed'] + 1;
				$job['last_tick_at'] = gmdate('c');
				if ($ok) {
					$job['ok'] = (int) $job['ok'] + 1;
				} else {
					$job['err'] = (int) $job['err'] + 1;
					$errors     = self::normalize_errors($job['errors'] ?? []);
					array_unshift($errors, $entry);
					$job['errors'] = array_slice($errors, 0, self::ERRORS_MAX);
				}
				$job['last_message'] = $line;
				$job['log']          = $this->push_log($job['log'] ?? [], $line, $ok);

				// Pause on Vision rate-limit so the queue does not burn the rest silently.
				if (! empty($result['rate_limited']) && $use_ai) {
					$job['status']       = 'paused';
					$job['pause_reason'] = 'rate_limit';
					$job['last_message'] = __(
						'Limite API Vision atteinte — file en pause. Reprenez plus tard, ou désactivez l’IA et continuez.',
						'lumen-wp'
					);
					$job['log'] = $this->push_log($job['log'] ?? [], $job['last_message'], false);
					self::save($job);
					wp_clear_scheduled_hook(self::CRON_HOOK);
					As_Bridge::cancel_bulk();

					return;
				}

				self::save($job);
				$processed_in_tick++;
			}

			$job = self::job();
			if (($job['status'] ?? '') === 'running') {
				$elapsed = microtime(true) - $started_at;
				$job['batch_size'] = $this->adapt_batch_size(
					$job,
					$processed_in_tick,
					$elapsed,
					$hit_budget || $elapsed >= ($tick_budget * 0.95)
				);
				self::save($job);
			}
		} finally {
			delete_transient(self::LOCK);
			// Reprogrammer même si le tick a été coupé (timeout) avant la fin.
			if ((self::job()['status'] ?? '') === 'running') {
				$this->schedule_soon();
			}
		}
	}

	/**
	 * Médias coincés en « processing » après un kill PHP / timeout → repasser en erreur retentable.
	 */
	public static function recover_stale_processing(int $max_age_seconds = 120): int
	{
		global $wpdb;

		$max_age_seconds = max(60, $max_age_seconds);
		$now             = time();
		$status_key      = Plugin::META_STATUS;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = %s AND meta_value = 'processing'
				LIMIT 100",
				$status_key
			)
		);
		// phpcs:enable

		$fixed = 0;
		$msg   = __('Traitement interrompu (timeout serveur) — sera retenté au prochain passage.', 'lumen-wp');

		foreach ($ids as $raw_id) {
			$id = (int) $raw_id;
			if ($id <= 0) {
				continue;
			}

			$started = (int) get_post_meta($id, Plugin::META_PROCESSING_AT, true);
			// Legacy / crash without stamp: treat as stale immediately.
			$age = $started > 0 ? ($now - $started) : $max_age_seconds + 1;
			if ($age < $max_age_seconds) {
				continue;
			}

			update_post_meta($id, Plugin::META_STATUS, 'error');
			update_post_meta($id, Plugin::META_ERROR, $msg);
			delete_post_meta($id, Plugin::META_PROCESSING_AT);
			++$fixed;
		}

		return $fixed;
	}

	/**
	 * @param array<string, mixed> $job
	 */
	private function adapt_batch_size(array $job, int $processed_in_tick, float $elapsed, bool $pressure): int
	{
		$min    = max(1, (int) ($job['batch_min'] ?? 1));
		$max    = max($min, (int) ($job['batch_max'] ?? 10));
		$budget = max(8, (int) ($job['tick_budget'] ?? 22));
		$size   = max($min, min($max, (int) ($job['batch_size'] ?? 2)));

		if ($pressure) {
			return max($min, $size - 1);
		}
		if ($processed_in_tick >= $size && $elapsed < ($budget * 0.6)) {
			return min($max, $size + 1);
		}

		return $size;
	}

	private function schedule_soon(): void
	{
		As_Bridge::enqueue_bulk_tick();
		wp_clear_scheduled_hook(self::CRON_HOOK);
		wp_schedule_single_event(time() + 1, self::CRON_HOOK);
	}

	private function spawn(): void
	{
		if (function_exists('spawn_cron')) {
			spawn_cron(time());
		}
	}

	private function guard(): void
	{
		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Permission refusée.', 'lumen-wp')], 403);
		}
		check_ajax_referer('lumen_wp_admin', 'nonce');
	}

	/**
	 * Public wrapper for async total estimate (As_Bridge).
	 *
	 * @param list<string> $types
	 */
	public function count_pending_public(bool $force, array $types): int
	{
		return $this->count_pending($force, $types);
	}

	/**
	 * @param list<string> $types
	 */
	private function count_pending(bool $force, array $types): int
	{
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$sql = $this->pending_sql($force, false, 0, $types);
		$total = (int) $wpdb->get_var($sql['sql']);
		// phpcs:enable

		return max(0, $total);
	}

	/**
	 * @param list<string> $types
	 */
	private function next_id(int $cursor, bool $force, array $types): int
	{
		global $wpdb;
		$built = $this->pending_sql($force, true, $cursor, $types);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$id = $built['args'] === []
			? $wpdb->get_var($built['sql'])
			: $wpdb->get_var($wpdb->prepare($built['sql'], ...$built['args']));
		// phpcs:enable

		return (int) $id;
	}

	/**
	 * @param list<string> $types
	 * @return array{sql: string, args: list<mixed>}
	 */
	private function pending_sql(bool $force, bool $next_only, int $cursor = 0, array $types = []): array
	{
		global $wpdb;

		$types    = Media_Types::normalize_types($types === [] ? Media_Types::all_types() : $types);
		$mime_sql = Media_Types::mime_where_sql($types, 'p');
		$status   = Plugin::META_STATUS;
		$variants = Plugin::META_VARIANTS;
		$args     = [];

		if ($force) {
			$sql = 'SELECT ' . ($next_only ? 'p.ID' : 'COUNT(p.ID)') . "
				FROM {$wpdb->posts} p
				WHERE p.post_type = 'attachment'
				  AND p.post_status = 'inherit'
				  AND {$mime_sql}";
			if ($next_only) {
				$sql .= ' AND p.ID > %d ORDER BY p.ID ASC LIMIT 1';
				$args[] = $cursor;
			}

			return ['sql' => $sql, 'args' => $args];
		}

		$replace = ! empty(Plugin::instance()->settings()['replace_original']);
		$pending_parts = [];

		if (in_array(Media_Types::KIND_IMAGE, $types, true)) {
			$img_mime = Media_Types::mime_where_sql([Media_Types::KIND_IMAGE], 'p');
			if ($replace) {
				$pending_parts[] = "(
					{$img_mime}
					AND NOT (
						s.meta_id IS NOT NULL
						AND v.meta_id IS NOT NULL
						AND p.post_mime_type IN ('image/webp', 'image/avif')
					)
				)";
			} else {
				$pending_parts[] = "(
					{$img_mime}
					AND (s.meta_id IS NULL OR v.meta_id IS NULL)
				)";
			}
		}

		foreach ([Media_Types::KIND_SVG, Media_Types::KIND_PDF, Media_Types::KIND_VIDEO] as $doc_kind) {
			if (! in_array($doc_kind, $types, true)) {
				continue;
			}
			$doc_mime = Media_Types::mime_where_sql([$doc_kind], 'p');
			$pending_parts[] = "({$doc_mime} AND s.meta_id IS NULL)";
		}

		if ($pending_parts === []) {
			return ['sql' => 'SELECT ' . ($next_only ? '0' : '0') . ' WHERE 0=1', 'args' => []];
		}

		$pending_sql = implode(' OR ', $pending_parts);

		$sql = 'SELECT ' . ($next_only ? 'p.ID' : 'COUNT(DISTINCT p.ID)') . "
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} s
				ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value IN ('ok', 'awaiting_validation', 'unsupported')
			LEFT JOIN {$wpdb->postmeta} v
				ON v.post_id = p.ID AND v.meta_key = %s AND v.meta_value != '' AND v.meta_value != 'a:0:{}'
			WHERE p.post_type = 'attachment'
			  AND p.post_status = 'inherit'
			  AND ({$pending_sql})";
		$args[] = $status;
		$args[] = $variants;

		if ($next_only) {
			$sql .= ' AND p.ID > %d ORDER BY p.ID ASC LIMIT 1';
			$args[] = $cursor;
		}

		// For COUNT, prepare with args.
		if (! $next_only && $args !== []) {
			$sql  = $wpdb->prepare($sql, ...$args) ?: $sql;
			$args = [];
		}

		return ['sql' => $sql, 'args' => $args];
	}

	/**
	 * Persist a light summary of a finished / stopped run.
	 *
	 * @param array<string, mixed> $job
	 */
	public static function push_history(array $job, string $ended): void
	{
		if (! empty($job['archived'])) {
			return;
		}
		if (($job['started_at'] ?? '') === '') {
			return;
		}

		$errors = self::normalize_errors($job['errors'] ?? []);
		// Fallback legacy : extraire depuis le log si aucune erreur structurée.
		if ($errors === []) {
			$log = is_array($job['log'] ?? null) ? $job['log'] : [];
			foreach ($log as $row) {
				if (! is_array($row) || ($row['ok'] ?? true) !== false) {
					continue;
				}
				$text = trim((string) ($row['t'] ?? ''));
				if ($text === '') {
					continue;
				}
				$parsed = self::normalize_errors([$text]);
				if ($parsed === []) {
					continue;
				}
				$errors[] = $parsed[0];
				if (count($errors) >= self::ERRORS_MAX) {
					break;
				}
			}
		}

		$entry = [
			'id'             => md5((string) $job['started_at'] . '|' . (string) ($job['user_id'] ?? 0)),
			'started_at'     => (string) $job['started_at'],
			'ended_at'       => gmdate('c'),
			'ended'          => $ended === 'done' ? 'done' : 'stopped',
			'ok'             => (int) ($job['ok'] ?? 0),
			'err'            => (int) ($job['err'] ?? 0),
			'processed'      => (int) ($job['processed'] ?? 0),
			'total_estimate' => (int) ($job['total_estimate'] ?? 0),
			'force'          => ! empty($job['force']),
			'use_ai'         => ! empty($job['use_ai']),
			'types'          => Media_Types::normalize_types($job['types'] ?? Media_Types::all_types()),
			'ai_provider'    => (string) ($job['ai_provider'] ?? 'none'),
			'ai_label'       => (string) ($job['ai_label'] ?? ''),
			'user_id'        => (int) ($job['user_id'] ?? 0),
			'user_name'      => (string) ($job['user_name'] ?? ''),
			'errors'         => $errors,
		];

		$history = self::history();
		// Évite un doublon si le même run est archivé deux fois.
		$history = array_values(
			array_filter(
				$history,
				static function ($row) use ($entry) {
					return ! is_array($row) || ($row['id'] ?? '') !== $entry['id'];
				}
			)
		);
		array_unshift($history, $entry);
		$history = array_slice($history, 0, self::HISTORY_MAX);
		update_option(self::HISTORY_OPTION, $history, false);
	}

	/**
	 * @param list<array{t: string, ok: bool}> $log
	 * @return list<array{t: string, ok: bool}>
	 */
	private function push_log(array $log, string $text, bool $ok): array
	{
		array_unshift($log, $this->log_line($text, $ok));

		return array_slice($log, 0, 30);
	}

	/**
	 * @return array{t: string, ok: bool}
	 */
	private function log_line(string $text, bool $ok): array
	{
		return ['t' => $text, 'ok' => $ok];
	}
}
