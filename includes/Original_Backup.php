<?php

declare(strict_types=1);

namespace LumenWp;

/**
 * Permanent original backup before replace_original, plus restore.
 */
final class Original_Backup
{
	/**
	 * @return array{path: string, mime: string, original_file: string, created_at: string}|null
	 */
	public static function meta(int $attachment_id): ?array
	{
		$raw = get_post_meta($attachment_id, Plugin::META_ORIGINAL_BACKUP, true);
		if (! is_array($raw) || empty($raw['path']) || empty($raw['mime'])) {
			return null;
		}

		return [
			'path'          => (string) $raw['path'],
			'mime'          => (string) $raw['mime'],
			'original_file' => (string) ($raw['original_file'] ?? ''),
			'created_at'    => (string) ($raw['created_at'] ?? ''),
		];
	}

	public static function has(int $attachment_id): bool
	{
		$meta = self::meta($attachment_id);
		if ($meta === null) {
			return false;
		}

		$path = self::resolve_path($meta['path']);

		return $path !== '' && is_readable($path);
	}

	/**
	 * Persist a first-time backup of the current original. Never overwrites an existing backup.
	 *
	 * @return array{path: string, mime: string, original_file: string, created_at: string}|null
	 */
	public static function ensure(int $attachment_id, string $original_path, string $mime): ?array
	{
		$existing = self::meta($attachment_id);
		if ($existing !== null) {
			$existing_abs = self::resolve_path($existing['path']);
			if ($existing_abs !== '' && is_readable($existing_abs)) {
				return $existing;
			}
		}

		if (! is_readable($original_path)) {
			return null;
		}

		$backup_abs = $original_path . '.lumen-original';
		if (! @copy($original_path, $backup_abs)) {
			throw new \RuntimeException(__('Impossible de créer la sauvegarde permanente de l’original.', 'lumen-wp'));
		}

		$rel = self::abs_to_rel($backup_abs);
		$meta = [
			'path'          => $rel !== '' ? $rel : $backup_abs,
			'mime'          => $mime !== '' ? $mime : (string) get_post_mime_type($attachment_id),
			'original_file' => self::abs_to_rel($original_path),
			'created_at'    => gmdate('c'),
		];

		update_post_meta($attachment_id, Plugin::META_ORIGINAL_BACKUP, $meta);

		return $meta;
	}

	/**
	 * Restore the backed-up original, regenerate WP sizes, clear Lumen sidecars.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function restore(int $attachment_id): array
	{
		$meta = self::meta($attachment_id);
		if ($meta === null) {
			return [
				'ok'      => false,
				'message' => __('Aucune sauvegarde d’original disponible.', 'lumen-wp'),
			];
		}

		$backup = self::resolve_path($meta['path']);
		if ($backup === '' || ! is_readable($backup)) {
			return [
				'ok'      => false,
				'message' => __('Fichier de sauvegarde introuvable.', 'lumen-wp'),
			];
		}

		$current = get_attached_file($attachment_id);
		$target  = self::resolve_path($meta['original_file']);
		if ($target === '') {
			// Fallback: strip .lumen-original from backup path.
			$target = preg_replace('/\.lumen-original$/', '', $backup) ?: '';
		}
		if ($target === '') {
			return [
				'ok'      => false,
				'message' => __('Chemin de restauration invalide.', 'lumen-wp'),
			];
		}

		$dir = dirname($target);
		if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
			return [
				'ok'      => false,
				'message' => __('Impossible de créer le dossier de restauration.', 'lumen-wp'),
			];
		}

		Cleanup::delete_sidecars($attachment_id);

		if (is_string($current) && $current !== '' && file_exists($current) && wp_normalize_path($current) !== wp_normalize_path($target)) {
			@unlink($current);
		}

		if (! @copy($backup, $target)) {
			return [
				'ok'      => false,
				'message' => __('Échec de la copie de restauration.', 'lumen-wp'),
			];
		}

		$mime = $meta['mime'] !== '' ? $meta['mime'] : 'image/jpeg';
		update_attached_file($attachment_id, $target);
		wp_update_post(
			[
				'ID'             => $attachment_id,
				'post_mime_type' => $mime,
			]
		);

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$generated = Hooks::without_auto_process(
			static function () use ($attachment_id, $target) {
				return wp_generate_attachment_metadata($attachment_id, $target);
			}
		);
		if (is_array($generated)) {
			wp_update_attachment_metadata($attachment_id, $generated);
		}

		delete_post_meta($attachment_id, Plugin::META_VARIANTS);
		delete_post_meta($attachment_id, Plugin::META_JSONLD);
		delete_post_meta($attachment_id, Plugin::META_GUTENBERG);
		delete_post_meta($attachment_id, Plugin::META_ERROR);
		update_post_meta($attachment_id, Plugin::META_STATUS, 'restored');

		clean_post_cache($attachment_id);

		return [
			'ok'      => true,
			'message' => __('Original restauré. Vous pouvez re-traiter le média.', 'lumen-wp'),
		];
	}

	/**
	 * Delete backup file and/or meta.
	 *
	 * @return array{deleted: int, bytes: int}
	 */
	public static function delete(int $attachment_id, bool $delete_meta = true): array
	{
		$deleted = 0;
		$bytes   = 0;
		$meta    = self::meta($attachment_id);
		if ($meta !== null) {
			$path = self::resolve_path($meta['path']);
			if ($path !== '' && file_exists($path)) {
				$size = (int) @filesize($path);
				if (@unlink($path)) {
					$deleted++;
					$bytes += max(0, $size);
				}
			}
		}

		if ($delete_meta) {
			delete_post_meta($attachment_id, Plugin::META_ORIGINAL_BACKUP);
		}

		return ['deleted' => $deleted, 'bytes' => $bytes];
	}

	public static function abs_to_rel(string $abs): string
	{
		$uploads = wp_upload_dir();
		$basedir = trailingslashit(str_replace('\\', '/', $uploads['basedir']));
		$norm    = str_replace('\\', '/', $abs);
		if (strpos($norm, $basedir) === 0) {
			return ltrim(substr($norm, strlen($basedir)), '/');
		}

		return '';
	}

	public static function resolve_path(string $stored): string
	{
		$stored = trim(str_replace('\\', '/', $stored));
		if ($stored === '') {
			return '';
		}

		if ($stored[0] === '/' || preg_match('#^[A-Za-z]:/#', $stored)) {
			return $stored;
		}

		$uploads = wp_upload_dir();

		return trailingslashit(str_replace('\\', '/', $uploads['basedir'])) . ltrim($stored, '/');
	}
}
