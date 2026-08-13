<?php

declare(strict_types=1);

namespace LumenWp;

/**
 * Thin wrapper around Action Scheduler (bundled or provided by WooCommerce).
 */
final class As_Bridge
{
	public const HOOK_BULK_TICK = 'lumen_wp_as_bulk_tick';
	public const HOOK_URLS_TICK = 'lumen_wp_as_urls_tick';
	public const HOOK_COUNT     = 'lumen_wp_as_count_estimate';

	public const GROUP_BULK = 'lumen-bulk';
	public const GROUP_URLS = 'lumen-urls';
	public const GROUP_META = 'lumen-meta';

	public static function bootstrap(): void
	{
		$path = LUMEN_WP_PATH . 'lib/action-scheduler/action-scheduler.php';
		if (is_readable($path)) {
			require_once $path;
		}
	}

	public function register(): void
	{
		add_action(self::HOOK_BULK_TICK, [$this, 'handle_bulk_tick']);
		add_action(self::HOOK_URLS_TICK, [$this, 'handle_urls_tick']);
		add_action(self::HOOK_COUNT, [$this, 'handle_count_estimate'], 10, 1);
	}

	public static function available(): bool
	{
		return function_exists('as_enqueue_async_action');
	}

	public static function enqueue_bulk_tick(): void
	{
		if (! self::available()) {
			return;
		}
		// Do not use as_has_scheduled_action here: the current tick still counts as
		// in-progress and would block re-scheduling the next one.
		as_schedule_single_action(time() + 1, self::HOOK_BULK_TICK, [], self::GROUP_BULK);
	}

	public static function enqueue_urls_tick(): void
	{
		if (! self::available()) {
			return;
		}
		as_schedule_single_action(time() + 1, self::HOOK_URLS_TICK, [], self::GROUP_URLS);
	}

	/**
	 * @param 'bulk'|'urls' $kind
	 */
	public static function enqueue_count_estimate(string $kind): void
	{
		if (! self::available()) {
			return;
		}
		$kind = $kind === 'urls' ? 'urls' : 'bulk';
		as_enqueue_async_action(self::HOOK_COUNT, [$kind], self::GROUP_META, true);
	}

	public static function cancel_bulk(): void
	{
		if (! self::available()) {
			return;
		}
		as_unschedule_all_actions(self::HOOK_BULK_TICK, [], self::GROUP_BULK);
	}

	public static function cancel_urls(): void
	{
		if (! self::available()) {
			return;
		}
		as_unschedule_all_actions(self::HOOK_URLS_TICK, [], self::GROUP_URLS);
	}

	/**
	 * Drain AS actions (Hostinger wakeup from admin poll).
	 */
	public static function run_pending(int $time_budget = 12): int
	{
		if (! self::available() || ! class_exists('\ActionScheduler_QueueRunner')) {
			return 0;
		}
		$time_budget = max(1, min(25, $time_budget));
		$filter      = static function () use ($time_budget) {
			return $time_budget;
		};
		add_filter('action_scheduler_queue_runner_time_limit', $filter);
		try {
			return (int) \ActionScheduler_QueueRunner::instance()->run('Lumen');
		} finally {
			remove_filter('action_scheduler_queue_runner_time_limit', $filter);
		}
	}

	public function handle_bulk_tick(): void
	{
		(new Bulk_Queue())->tick();
	}

	public function handle_urls_tick(): void
	{
		(new Url_Queue())->tick();
	}

	/**
	 * @param mixed $kind
	 */
	public function handle_count_estimate($kind = 'bulk'): void
	{
		$kind = is_string($kind) && $kind === 'urls' ? 'urls' : 'bulk';

		if ($kind === 'urls') {
			$job = Url_Queue::job();
			if (! in_array(($job['status'] ?? ''), ['running', 'done'], true)) {
				return;
			}
			$started = (string) ($job['started_at'] ?? '');
			$force   = ! empty($job['force_full']);
			$total   = Content_Url_Rewriter::count_candidate_attachments($force);
			$fresh   = Url_Queue::job();
			if ((string) ($fresh['started_at'] ?? '') !== $started) {
				return;
			}
			$fresh['total_estimate'] = $total;
			Url_Queue::save($fresh);

			return;
		}

		$job = Bulk_Queue::job();
		if (! in_array(($job['status'] ?? ''), ['running', 'done'], true)) {
			return;
		}
		$started = (string) ($job['started_at'] ?? '');
		$force   = ! empty($job['force']);
		$types   = Media_Types::normalize_types($job['types'] ?? Media_Types::all_types());
		$total   = (new Bulk_Queue())->count_pending_public($force, $types);
		$fresh   = Bulk_Queue::job();
		if ((string) ($fresh['started_at'] ?? '') !== $started) {
			return;
		}
		$fresh['total_estimate'] = $total;
		if (($fresh['status'] ?? '') === 'running' && (string) ($fresh['last_message'] ?? '') !== '') {
			// Keep last tick message; only fill estimate.
		}
		Bulk_Queue::save($fresh);
	}
}
