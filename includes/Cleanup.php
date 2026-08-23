<?php

declare(strict_types=1);

namespace LumenWp;

/**
 * Cleanup Lumen sidecars / backups for one attachment or the whole library.
 */
final class Cleanup
{
	/**
	 * Absolute paths of sidecar files listed in META_VARIANTS (never the main attached file).
	 *
	 * @return list<string>
	 */
	public static function sidecar_paths(int $attachment_id): array
	{
		$variants = get_post_meta($attachment_id, Plugin::META_VARIANTS, true);
		if (! is_array($variants)) {
			return [];
		}

		$main = get_attached_file($attachment_id);
		$main_norm = is_string($main) && $main !== '' ? wp_normalize_path($main) : '';

		$paths = [];
		$uploads = wp_upload_dir();
		$basedir = wp_normalize_path(trailingslashit(str_replace('\\', '/', $uploads['basedir'])));
		foreach ($variants as $row) {
			if (! is_array($row) || empty($row['files']) || ! is_array($row['files'])) {
				continue;
			}
			foreach ($row['files'] as $file) {
				if (! is_array($file) || empty($file['path'])) {
					continue;
				}
				$path = (string) $file['path'];
				if ($path === '' || ! file_exists($path)) {
					continue;
				}
				$norm = wp_normalize_path($path);
				if (strpos($norm, $basedir) !== 0) {
					continue;
				}
				if ($main_norm !== '' && $norm === $main_norm) {
					continue;
				}
				$paths[] = $path;
			}
		}

		return array_values(array_unique($paths));
	}

	/**
	 * @return array{deleted: int, failed: int, bytes: int}
	 */
	public static function delete_sidecars(int $attachment_id): array
	{
		$deleted = 0;
		$failed  = 0;
		$bytes   = 0;

		foreach (self::sidecar_paths($attachment_id) as $path) {
			$size = (int) @filesize($path);
			if (@unlink($path)) {
				$deleted++;
				$bytes += max(0, $size);
			} else {
				$failed++;
			}
		}

		delete_post_meta($attachment_id, Plugin::META_VARIANTS);
		delete_post_meta($attachment_id, Plugin::META_JSONLD);
		delete_post_meta($attachment_id, Plugin::META_GUTENBERG);

		return [
			'deleted' => $deleted,
			'failed'  => $failed,
			'bytes'   => $bytes,
		];
	}

	/**
	 * @return list<int>
	 */
	public static function attachment_ids_with_lumen_meta(): array
	{
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				WHERE meta_key IN (%s, %s, %s)
				ORDER BY post_id ASC",
				Plugin::META_VARIANTS,
				Plugin::META_ORIGINAL_BACKUP,
				Plugin::META_STATUS
			)
		);
		// phpcs:enable

		return array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [])));
	}

	/**
	 * Dry-run summary for the Tools page.
	 *
	 * @return array{attachments: int, sidecars: int, sidecar_bytes: int, backups: int, backup_bytes: int}
	 */
	public static function preview(): array
	{
		$ids           = self::attachment_ids_with_lumen_meta();
		$sidecars      = 0;
		$sidecar_bytes = 0;
		$backups       = 0;
		$backup_bytes  = 0;

		foreach ($ids as $id) {
			foreach (self::sidecar_paths($id) as $path) {
				$sidecars++;
				$sidecar_bytes += max(0, (int) @filesize($path));
			}
			$meta = Original_Backup::meta($id);
			if ($meta !== null) {
				$path = Original_Backup::resolve_path($meta['path']);
				if ($path !== '' && file_exists($path)) {
					$backups++;
					$backup_bytes += max(0, (int) @filesize($path));
				}
			}
		}

		return [
			'attachments'   => count($ids),
			'sidecars'      => $sidecars,
			'sidecar_bytes' => $sidecar_bytes,
			'backups'       => $backups,
			'backup_bytes'  => $backup_bytes,
		];
	}

	/**
	 * @param array{sidecars?: bool, backups?: bool, clear_status?: bool} $opts
	 * @return array{attachments: int, deleted: int, failed: int, bytes: int}
	 */
	public static function run(array $opts): array
	{
		$do_sidecars     = ! empty($opts['sidecars']);
		$do_backups      = ! empty($opts['backups']);
		$do_clear_status = ! empty($opts['clear_status']);

		$attachments = 0;
		$deleted     = 0;
		$failed      = 0;
		$bytes       = 0;

		foreach (self::attachment_ids_with_lumen_meta() as $id) {
			$touched = false;

			if ($do_sidecars) {
				$r = self::delete_sidecars($id);
				$deleted += $r['deleted'];
				$failed  += $r['failed'];
				$bytes   += $r['bytes'];
				if ($r['deleted'] > 0 || $r['failed'] > 0) {
					$touched = true;
				}
			}

			if ($do_backups) {
				$r = Original_Backup::delete($id, true);
				$deleted += $r['deleted'];
				$bytes   += $r['bytes'];
				if ($r['deleted'] > 0) {
					$touched = true;
				}
			}

			if ($do_clear_status) {
				delete_post_meta($id, Plugin::META_STATUS);
				delete_post_meta($id, Plugin::META_ERROR);
				delete_post_meta($id, Plugin::META_URLS_CLEAN);
				$touched = true;
			}

			if ($touched) {
				$attachments++;
			}
		}

		return [
			'attachments' => $attachments,
			'deleted'     => $deleted,
			'failed'      => $failed,
			'bytes'       => $bytes,
		];
	}

	public static function format_bytes(int $bytes): string
	{
		if ($bytes < 1024) {
			return $bytes . ' B';
		}
		if ($bytes < 1024 * 1024) {
			return round($bytes / 1024, 1) . ' KB';
		}

		return round($bytes / (1024 * 1024), 2) . ' MB';
	}
}
