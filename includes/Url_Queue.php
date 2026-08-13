<?php

declare(strict_types=1);

namespace LumenWp;

/**
 * Chunked diagnose / rewrite of stale media URLs (Hostinger-safe).
 */
final class Url_Queue
{
	public const OPTION = 'lumen_wp_urls_job';
	public const CRON_HOOK = 'lumen_wp_urls_tick';
	public const LOCK = 'lumen_wp_urls_lock';
	public const ISSUES_MAX = 100;
	public const LOG_MAX = 30;

	public function register(): void
	{
		add_action(self::CRON_HOOK, [$this, 'tick']);
		add_action('wp_ajax_lumen_wp_urls_start', [$this, 'ajax_start']);
		add_action('wp_ajax_lumen_wp_urls_stop', [$this, 'ajax_stop']);
		add_action('wp_ajax_lumen_wp_urls_status', [$this, 'ajax_status']);
		add_action('wp_ajax_lumen_wp_urls_force_tick', [$this, 'ajax_force_tick']);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array
	{
		return [
			'status'         => 'idle',
			'mode'           => 'diagnose',
			'cursor'         => 0,
			'total_estimate' => 0,
			'processed'      => 0,
			'issues_found'   => 0,
			'attachments'    => 0,
			'replacements'   => 0,
			'posts'          => 0,
			'metas'          => 0,
			'options'        => 0,
			'last_message'   => '',
			'started_at'     => '',
			'updated_at'     => '',
			'last_tick_at'   => '',
			'user_id'        => 0,
			'log'            => [],
			'issues'         => [],
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
				if ($ts !== false && (time() - $ts) > 120) {
					$stale = true;
				}
			} elseif (! empty($job['started_at'])) {
				$ts = strtotime((string) $job['started_at']);
				if ($ts !== false && (time() - $ts) > 90) {
					$stale = true;
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
			'hook'           => self::CRON_HOOK,
		];
	}

	public function ajax_start(): void
	{
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification
		$mode = isset($_POST['mode']) ? sanitize_key((string) wp_unslash($_POST['mode'])) : 'diagnose';
		if (! in_array($mode, ['diagnose', 'rewrite'], true)) {
			wp_send_json_error(['message' => __('Mode invalide.', 'lumen-wp')], 400);
		}

		$current = self::job();
		if (($current['status'] ?? '') === 'running') {
			wp_send_json_error(['message' => __('Un scan URLs est déjà en cours.', 'lumen-wp')], 409);
		}

		@set_time_limit(60); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$total = Content_Url_Rewriter::count_candidate_attachments();
		$user  = wp_get_current_user();

		$job                   = self::defaults();
		$job['status']         = 'running';
		$job['mode']           = $mode;
		$job['cursor']         = 0;
		$job['total_estimate'] = $total;
		$job['started_at']     = gmdate('c');
		$job['user_id']        = (int) $user->ID;
		$job['last_message']   = $mode === 'rewrite'
			? sprintf(
				/* translators: %d: candidates */
				__('Réécriture démarrée — %d média(s) à parcourir.', 'lumen-wp'),
				$total
			)
			: sprintf(
				/* translators: %d: candidates */
				__('Diagnostic démarré — %d média(s) à parcourir.', 'lumen-wp'),
				$total
			);
		$job['log'] = [$this->log_line($job['last_message'])];
		self::save($job);

		$this->schedule_soon();
		$this->spawn();

		wp_send_json_success(
			[
				'job'    => self::job(),
				'health' => self::health(),
			]
		);
	}

	public function ajax_stop(): void
	{
		$this->guard();
		$job                 = self::job();
		$job['status']       = 'idle';
		$job['last_message'] = __('Arrêté.', 'lumen-wp');
		$job['log']          = $this->push_log($job['log'] ?? [], $job['last_message']);
		self::save($job);
		wp_clear_scheduled_hook(self::CRON_HOOK);
		delete_transient(self::LOCK);

		wp_send_json_success(
			[
				'job'    => self::job(),
				'health' => self::health(),
			]
		);
	}

	public function ajax_status(): void
	{
		$this->guard();
		$job = self::job();

		if (($job['status'] ?? '') === 'running') {
			if (! wp_next_scheduled(self::CRON_HOOK)) {
				$this->schedule_soon();
				$this->spawn();
			}
			// Hostinger / low traffic: nudge a tick from the open Tools page.
			$health = self::health();
			if (! empty($health['stale']) || ! empty($health['cron_disabled'])) {
				delete_transient(self::LOCK);
				$this->tick();
			}
		}

		wp_send_json_success(
			[
				'job'    => self::job(),
				'health' => self::health(),
			]
		);
	}

	public function ajax_force_tick(): void
	{
		$this->guard();
		$job = self::job();
		if (($job['status'] ?? '') !== 'running') {
			wp_send_json_error(['message' => __('Aucun scan URLs en cours.', 'lumen-wp')], 400);
		}

		delete_transient(self::LOCK);
		$this->tick();
		if ((self::job()['status'] ?? '') === 'running') {
			$this->schedule_soon();
			$this->spawn();
		}

		wp_send_json_success(
			[
				'job'    => self::job(),
				'health' => self::health(),
			]
		);
	}

	public function tick(): void
	{
		if (get_transient(self::LOCK)) {
			return;
		}
		set_transient(self::LOCK, 1, 120);
		@set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		try {
			$job                  = self::job();
			$job['last_tick_at']  = gmdate('c');
			self::save($job);

			if (($job['status'] ?? '') !== 'running') {
				return;
			}

			$mode = (string) ($job['mode'] ?? 'diagnose');
			$next = Content_Url_Rewriter::next_candidate_id((int) ($job['cursor'] ?? 0));

			if ($next <= 0) {
				$this->finalize($job, $mode);

				return;
			}

			$pairs = Content_Url_Rewriter::pairs_for_attachment($next);
			$line  = '#' . $next;

			if ($pairs === []) {
				$job['cursor']       = $next;
				$job['processed']    = (int) $job['processed'] + 1;
				$job['last_message'] = $line . ' — ' . __('aucun chemin obsolète', 'lumen-wp');
				$job['log']          = $this->push_log($job['log'] ?? [], $job['last_message']);
				self::save($job);
				$this->schedule_soon();

				return;
			}

			$title = (string) ($pairs[0]['title'] ?? ('#' . $next));
			$line  = '#' . $next . ' — ' . $title;

			if ($mode === 'rewrite') {
				$att_hits = 0;
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
					if ((int) ($r['replacements'] ?? 0) > 0) {
						$att_hits++;
					}
				}
				if ($att_hits > 0) {
					$job['attachments'] = (int) $job['attachments'] + 1;
				}
				$job['last_message'] = $line . ' — ' . sprintf(
					/* translators: %d: replacements */
					__('%d remplacement(s)', 'lumen-wp'),
					(int) ($job['replacements'] ?? 0)
				);
			} else {
				foreach ($pairs as $pair) {
					$issue = Content_Url_Rewriter::diagnose_pair($pair);
					if ($issue === null) {
						continue;
					}
					$job['issues_found'] = (int) $job['issues_found'] + 1;
					$issues              = is_array($job['issues'] ?? null) ? $job['issues'] : [];
					if (count($issues) < self::ISSUES_MAX) {
						$issues[]       = $issue;
						$job['issues']  = $issues;
					}
				}
				$job['last_message'] = $line . ' — ' . sprintf(
					/* translators: %d: issues so far */
					__('%d URL(s) obsolète(s) trouvée(s)', 'lumen-wp'),
					(int) $job['issues_found']
				);
			}

			$job['cursor']    = $next;
			$job['processed'] = (int) $job['processed'] + 1;
			$job['log']       = $this->push_log($job['log'] ?? [], $job['last_message']);
			self::save($job);

			if (($job['status'] ?? '') === 'running') {
				$this->schedule_soon();
			}
		} finally {
			delete_transient(self::LOCK);
		}
	}

	/**
	 * @param array<string, mixed> $job
	 */
	private function finalize(array $job, string $mode): void
	{
		if ($mode === 'rewrite') {
			Content_Url_Rewriter::clear_elementor_cache();
			$job['last_message'] = sprintf(
				/* translators: 1: attachments 2: replacements */
				__('Réécriture terminée — %1$d média(s), %2$d remplacement(s).', 'lumen-wp'),
				(int) ($job['attachments'] ?? 0),
				(int) ($job['replacements'] ?? 0)
			);
		} else {
			$job['last_message'] = sprintf(
				/* translators: 1: scanned 2: issues */
				__('Diagnostic terminé — %1$d média(s) scanné(s), %2$d URL(s) obsolète(s).', 'lumen-wp'),
				(int) ($job['processed'] ?? 0),
				(int) ($job['issues_found'] ?? 0)
			);
		}

		$job['status'] = 'done';
		$job['log']    = $this->push_log($job['log'] ?? [], $job['last_message']);
		self::save($job);
		wp_clear_scheduled_hook(self::CRON_HOOK);
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

	private function guard(): void
	{
		if (! current_user_can('upload_files')) {
			wp_send_json_error(['message' => __('Permission refusée.', 'lumen-wp')], 403);
		}
		check_ajax_referer('lumen_wp_admin', 'nonce');
	}

	/**
	 * @param list<string> $log
	 * @return list<string>
	 */
	private function push_log($log, string $line): array
	{
		if (! is_array($log)) {
			$log = [];
		}
		array_unshift($log, $this->log_line($line));

		return array_slice($log, 0, self::LOG_MAX);
	}

	private function log_line(string $message): string
	{
		return wp_date('H:i:s') . ' — ' . $message;
	}
}
