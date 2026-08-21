<?php

declare(strict_types=1);

namespace LumenWp;

final class Job_Repository
{
	public static function table(): string
	{
		global $wpdb;

		return $wpdb->prefix . 'lumen_jobs';
	}

	/**
	 * @param array{
	 *   attachment_id: int,
	 *   type?: string,
	 *   status: string,
	 *   provider_used?: ?string,
	 *   tokens_prompt?: ?int,
	 *   tokens_completion?: ?int,
	 *   tokens_total?: ?int,
	 *   tokens_source?: ?string,
	 *   error_message?: ?string,
	 *   created_at?: string,
	 *   completed_at?: ?string
	 * } $row
	 */
	public static function insert(array $row): int
	{
		global $wpdb;

		$attachment_id = (int) ($row['attachment_id'] ?? 0);
		$status        = (string) ($row['status'] ?? '');

		if ($attachment_id <= 0 || $status === '') {
			return 0;
		}

		$now = gmdate('Y-m-d H:i:s');

		$data = [
			'attachment_id'     => $attachment_id,
			'type'              => (string) ($row['type'] ?? 'process'),
			'status'            => $status,
			'provider_used'     => $row['provider_used'] ?? null,
			'tokens_prompt'     => isset($row['tokens_prompt']) ? (int) $row['tokens_prompt'] : null,
			'tokens_completion' => isset($row['tokens_completion']) ? (int) $row['tokens_completion'] : null,
			'tokens_total'      => isset($row['tokens_total']) ? (int) $row['tokens_total'] : null,
			'tokens_source'     => $row['tokens_source'] ?? null,
			'error_message'     => $row['error_message'] ?? null,
			'created_at'        => (string) ($row['created_at'] ?? $now),
			'completed_at'      => (string) ($row['completed_at'] ?? $now),
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(self::table(), $data);

		if ($result === false) {
			error_log('[Lumen] job insert failed: ' . $wpdb->last_error);

			return 0;
		}

		$job_id  = (int) $wpdb->insert_id;
		$job_row = array_merge($row, $data, ['id' => $job_id]);
		self::mirror_last_job($attachment_id, $job_row);

		return $job_id;
	}

	/**
	 * @param array<string, mixed> $job_row
	 */
	public static function mirror_last_job(int $attachment_id, array $job_row): void
	{
		update_post_meta($attachment_id, Plugin::META_LAST_JOB, [
			'job_id'        => (int) ($job_row['id'] ?? 0),
			'tokens_total'  => isset($job_row['tokens_total']) ? (int) $job_row['tokens_total'] : null,
			'provider'      => $job_row['provider_used'] ?? null,
			'status'        => (string) ($job_row['status'] ?? ''),
			'completed_at'  => (string) ($job_row['completed_at'] ?? ''),
			'tokens_source' => $job_row['tokens_source'] ?? null,
		]);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function list_by_attachment(int $attachment_id, int $limit = 10): array
	{
		global $wpdb;

		if ($attachment_id <= 0) {
			return [];
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE attachment_id = %d ORDER BY id DESC LIMIT %d",
				$attachment_id,
				$limit
			),
			ARRAY_A
		);

		if (! is_array($rows)) {
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (! is_array($row)) {
				continue;
			}
			$out[] = self::cast_row($row);
		}

		return $out;
	}

	public static function sum_tokens_month(?string $month_key = null): int
	{
		global $wpdb;

		$month = $month_key ?: gmdate('Y-m');
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(tokens_total), 0) FROM {$table}
				WHERE completed_at LIKE %s AND tokens_total IS NOT NULL",
				$month . '%'
			)
		);
	}

	/**
	 * @return array{jobs: int, metas: int}
	 */
	public static function purge_all(): array
	{
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching — no user input
		$jobs = (int) $wpdb->query('DELETE FROM ' . self::table());
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$metas = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
				Plugin::META_LAST_JOB
			)
		);

		return ['jobs' => max(0, $jobs), 'metas' => max(0, $metas)];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function cast_row(array $row): array
	{
		$int_fields = ['id', 'attachment_id', 'tokens_prompt', 'tokens_completion', 'tokens_total'];

		foreach ($int_fields as $field) {
			if (array_key_exists($field, $row) && $row[$field] !== null) {
				$row[$field] = (int) $row[$field];
			}
		}

		return $row;
	}
}
