<?php

declare(strict_types=1);

namespace LumenWp;

final class Bulk_Queue
{
	public const OPTION = 'lumen_wp_bulk_job';
	public const HISTORY_OPTION = 'lumen_wp_bulk_history';
	public const HISTORY_MAX = 10;
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
			'ai_provider'    => 'none',
			'ai_label'       => '',
			'cursor'         => 0,
			'total_estimate' => 0,
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
			'log'            => [],
		];
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
				$out[] = $row;
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

	public function ajax_start(): void
	{
		$this->guard();

		$force  = ! empty($_POST['force']); // phpcs:ignore WordPress.Security.NonceVerification
		$use_ai = ! empty($_POST['use_ai']) || ! empty($_POST['use_mistral']); // phpcs:ignore WordPress.Security.NonceVerification

		$current = self::job();
		if (($current['status'] ?? '') === 'running') {
			wp_send_json_error(['message' => __('Un traitement est déjà en cours.', 'lumen-wp')], 409);
		}

		// Archive un run précédent non encore historisé (ex. terminé non pollé).
		if (! empty($current['started_at']) && empty($current['archived'])) {
			self::push_history($current, ($current['status'] ?? '') === 'done' ? 'done' : 'stopped');
		}

		$user  = wp_get_current_user();
		$total = $this->count_pending($force);
		$job   = self::defaults();
		$job['status']         = 'running';
		$job['force']          = $force;
		$job['use_ai']         = $use_ai;
		$job['ai_provider']    = $use_ai ? Vision_Ai::active_provider() : 'none';
		$job['ai_label']       = $use_ai ? Vision_Ai::provider_label(Vision_Ai::active_provider()) : '';
		$job['cursor']         = 0;
		$job['total_estimate'] = $total;
		$job['started_at']     = gmdate('c');
		$job['user_id']        = (int) $user->ID;
		$job['user_name']      = (string) ($user->display_name !== '' ? $user->display_name : $user->user_login);
		$job['last_message']   = sprintf(
			/* translators: %d: estimated total */
			__('Démarré — %d image(s) estimée(s).', 'lumen-wp'),
			$total
		);
		$job['log'] = [$this->log_line($job['last_message'], true)];
		self::save($job);

		$this->schedule_soon();
		$this->spawn();

		wp_send_json_success([
			'job'     => self::job(),
			'history' => self::history(),
		]);
	}

	public function ajax_pause(): void
	{
		$this->guard();
		$job = self::job();
		if (($job['status'] ?? '') !== 'running') {
			wp_send_json_error(['message' => __('Aucun traitement en cours.', 'lumen-wp')], 400);
		}
		$job['status'] = 'paused';
		$job['last_message'] = __('En pause.', 'lumen-wp');
		$job['log'] = $this->push_log($job['log'] ?? [], $job['last_message'], true);
		self::save($job);
		wp_clear_scheduled_hook(self::CRON_HOOK);
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

		// Relance un tick si running mais pas de cron planifié (sites sans trafic).
		if (($job['status'] ?? '') === 'running' && ! wp_next_scheduled(self::CRON_HOOK)) {
			$this->schedule_soon();
			$this->spawn();
		}

		wp_send_json_success([
			'job'            => $job,
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
		]);
	}

	public function ajax_force_tick(): void
	{
		$this->guard();
		$job = self::job();
		if (($job['status'] ?? '') !== 'running') {
			wp_send_json_error(['message' => __('Aucun traitement en cours à relancer.', 'lumen-wp')], 400);
		}

		delete_transient(self::LOCK);
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
	}

	public function tick(): void
	{
		if (get_transient(self::LOCK)) {
			return;
		}
		set_transient(self::LOCK, 1, 120);

		try {
			$job = self::job();
			$job['last_tick_at'] = gmdate('c');
			self::save($job);

			if (($job['status'] ?? '') !== 'running') {
				return;
			}

			$force  = ! empty($job['force']);
			$use_ai = ! empty($job['use_ai']);
			$next   = $this->next_id((int) $job['cursor'], $force);

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

				return;
			}

			$result = (new Hooks())->process($next, $force, $use_ai);
			$ok     = ! empty($result['ok']);
			$msg    = (string) ($result['message'] ?? ($result['status'] ?? ''));
			$line   = '#' . $next . ' — ' . ($msg !== '' ? $msg : ($ok ? 'ok' : 'error'));

			$job['cursor']    = $next;
			$job['processed'] = (int) $job['processed'] + 1;
			if ($ok) {
				$job['ok'] = (int) $job['ok'] + 1;
			} else {
				$job['err'] = (int) $job['err'] + 1;
			}
			$job['last_message'] = $line;
			$job['log']          = $this->push_log($job['log'] ?? [], $line, $ok);
			self::save($job);

			if (($job['status'] ?? '') === 'running') {
				$this->schedule_soon();
			}
		} finally {
			delete_transient(self::LOCK);
		}
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

	private function count_pending(bool $force): int
	{
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$sql = $this->pending_sql($force, false);
		$total = (int) $wpdb->get_var($sql['sql']);
		// phpcs:enable

		return max(0, $total);
	}

	private function next_id(int $cursor, bool $force): int
	{
		global $wpdb;
		$built = $this->pending_sql($force, true, $cursor);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$id = $wpdb->get_var($wpdb->prepare($built['sql'], ...$built['args']));
		// phpcs:enable

		return (int) $id;
	}

	/**
	 * @return array{sql: string, args: list<mixed>}
	 */
	private function pending_sql(bool $force, bool $next_only, int $cursor = 0): array
	{
		global $wpdb;

		$status   = Plugin::META_STATUS;
		$variants = Plugin::META_VARIANTS;
		$args     = [];

		if ($force) {
			$sql = "SELECT " . ($next_only ? 'p.ID' : 'COUNT(p.ID)') . "
				FROM {$wpdb->posts} p
				WHERE p.post_type = 'attachment'
				  AND p.post_status = 'inherit'
				  AND p.post_mime_type LIKE 'image/%'";
			if ($next_only) {
				$sql .= ' AND p.ID > %d ORDER BY p.ID ASC LIMIT 1';
				$args[] = $cursor;
			}

			return ['sql' => $sql, 'args' => $args];
		}

		$replace = ! empty(Plugin::instance()->settings()['replace_original']);

		if ($replace) {
			$sql = "SELECT " . ($next_only ? 'p.ID' : 'COUNT(DISTINCT p.ID)') . "
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} s
					ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = 'ok'
				LEFT JOIN {$wpdb->postmeta} v
					ON v.post_id = p.ID AND v.meta_key = %s AND v.meta_value != '' AND v.meta_value != 'a:0:{}'
				WHERE p.post_type = 'attachment'
				  AND p.post_status = 'inherit'
				  AND p.post_mime_type LIKE 'image/%%'
				  AND NOT (
					s.meta_id IS NOT NULL
					AND v.meta_id IS NOT NULL
					AND p.post_mime_type IN ('image/webp', 'image/avif')
				  )";
			$args[] = $status;
			$args[] = $variants;
		} else {
			$sql = "SELECT " . ($next_only ? 'p.ID' : 'COUNT(DISTINCT p.ID)') . "
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} s
					ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = 'ok'
				LEFT JOIN {$wpdb->postmeta} v
					ON v.post_id = p.ID AND v.meta_key = %s AND v.meta_value != '' AND v.meta_value != 'a:0:{}'
				WHERE p.post_type = 'attachment'
				  AND p.post_status = 'inherit'
				  AND p.post_mime_type LIKE 'image/%%'
				  AND (s.meta_id IS NULL OR v.meta_id IS NULL)";
			$args[] = $status;
			$args[] = $variants;
		}

		if ($next_only) {
			$sql .= ' AND p.ID > %d ORDER BY p.ID ASC LIMIT 1';
			$args[] = $cursor;
		}

		// For COUNT, prepare with args.
		if (! $next_only && $args !== []) {
			global $wpdb;
			$sql = $wpdb->prepare($sql, ...$args) ?: $sql;
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

		$errors = [];
		$log    = is_array($job['log'] ?? null) ? $job['log'] : [];
		foreach ($log as $row) {
			if (! is_array($row) || ($row['ok'] ?? true) !== false) {
				continue;
			}
			$text = trim((string) ($row['t'] ?? ''));
			if ($text === '') {
				continue;
			}
			$errors[] = $text;
			if (count($errors) >= 5) {
				break;
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
