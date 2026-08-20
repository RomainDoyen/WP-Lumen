<?php

declare(strict_types=1);

namespace LumenWp;

/**
 * Attachments touched by Lumen, for the Historique timeline.
 */
final class History_Query
{
	public const PER_PAGE = 25;

	/** @var list<string> */
	public const FILTERS = ['all', 'processing', 'awaiting_validation', 'ok', 'error'];

	/**
	 * @return array<string, int>
	 */
	public static function counts(): array
	{
		$out = [
			'all'                 => 0,
			'processing'          => 0,
			'awaiting_validation' => 0,
			'ok'                  => 0,
			'error'               => 0,
		];

		foreach (['processing', 'awaiting_validation', 'ok', 'error'] as $status) {
			$out[$status] = self::count($status);
			$out['all']  += $out[$status];
		}

		return $out;
	}

	public static function count(string $filter): int
	{
		global $wpdb;
		$status = Plugin::META_STATUS;
		$filter = self::normalize_filter($filter);

		if ($filter === 'all') {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT p.ID)
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} s
						ON s.post_id = p.ID AND s.meta_key = %s
						AND s.meta_value IN ('processing','awaiting_validation','ok','error')
					WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'",
					$status
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} s
					ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s
				WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'",
				$status,
				$filter
			)
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function list(string $filter, int $limit = self::PER_PAGE, int $offset = 0): array
	{
		global $wpdb;
		$status = Plugin::META_STATUS;
		$filter = self::normalize_filter($filter);
		$limit  = max(1, min(100, $limit));
		$offset = max(0, $offset);

		if ($filter === 'all') {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} s
						ON s.post_id = p.ID AND s.meta_key = %s
						AND s.meta_value IN ('processing','awaiting_validation','ok','error')
					WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
					ORDER BY p.post_modified_gmt DESC, p.ID DESC
					LIMIT %d OFFSET %d",
					$status,
					$limit,
					$offset
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} s
						ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s
					WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
					ORDER BY p.post_modified_gmt DESC, p.ID DESC
					LIMIT %d OFFSET %d",
					$status,
					$filter,
					$limit,
					$offset
				)
			);
		}

		$rows = [];
		foreach (array_map('intval', is_array($ids) ? $ids : []) as $id) {
			if ($id <= 0) {
				continue;
			}
			$rows[] = self::row($id);
		}

		return $rows;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function row(int $id): array
	{
		$post   = get_post($id);
		$status = (string) get_post_meta($id, Plugin::META_STATUS, true);
		$title  = $post ? (string) $post->post_title : '';
		if ($title === '') {
			$file  = get_attached_file($id);
			$title = is_string($file) && $file !== '' ? basename($file) : '#' . $id;
		}

		$thumb = wp_get_attachment_image_url($id, [64, 64]);
		if (! is_string($thumb) || $thumb === '') {
			$thumb = wp_get_attachment_image_url($id, 'thumbnail');
		}

		$kind = Media_Types::kind($id);
		$mod  = $post ? (string) $post->post_modified : '';
		$date = $mod !== '' && $mod !== '0000-00-00 00:00:00'
			? mysql2date('d/m/Y H:i', $mod)
			: '';

		return [
			'id'                => $id,
			'title'             => $title,
			'kind'              => $kind,
			'kind_label'        => Media_Types::label($kind),
			'status'            => $status,
			'status_label'      => self::status_label($status),
			'compression_label' => self::compression_label($id, $kind),
			'ai_label'          => self::ai_label($id, $status),
			'date'              => $date,
			'thumb_url'         => is_string($thumb) ? $thumb : '',
			'edit_url'          => get_edit_post_link($id, 'raw') ?: admin_url('upload.php?item=' . $id),
			'validation_url'    => $status === 'awaiting_validation'
				? \LumenWp\Admin\Validation::tab_url()
				: '',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function detail(int $id): array
	{
		$row     = self::row($id);
		$seo     = get_post_meta($id, Plugin::META_SEO, true);
		$pending = get_post_meta($id, Plugin::META_SEO_PENDING, true);
		$error   = (string) get_post_meta($id, Plugin::META_ERROR, true);
		$variants = get_post_meta($id, Plugin::META_VARIANTS, true);

		if (! is_array($seo)) {
			$seo = [];
		}
		if (! is_array($pending)) {
			$pending = [];
		}

		$alt = (string) ($seo['alt_text'] ?? $seo['alt_text_wcag'] ?? $seo['alt_text_seo'] ?? '');
		if ($alt === '' && $pending !== []) {
			$alt = (string) ($pending['alt_text'] ?? $pending['alt_text_wcag'] ?? $pending['alt_text_seo'] ?? '');
		}

		$formats = [];
		if (is_array($variants)) {
			foreach ($variants as $key => $val) {
				if (is_string($key) && $key !== '') {
					$formats[] = strtoupper($key);
				} elseif (is_array($val) && isset($val['format'])) {
					$formats[] = strtoupper((string) $val['format']);
				}
			}
		}

		return array_merge(
			$row,
			[
				'error'       => $error,
				'alt'         => $alt,
				'title_seo'   => (string) ($seo['title'] ?? $pending['title'] ?? ''),
				'caption'     => (string) ($seo['caption'] ?? $pending['caption'] ?? ''),
				'description' => (string) ($seo['description'] ?? $pending['description'] ?? ''),
				'formats'     => array_values(array_unique($formats)),
				'large_url'   => (string) (wp_get_attachment_image_url($id, 'medium') ?: $row['thumb_url']),
			]
		);
	}

	public static function status_label(string $status): string
	{
		switch ($status) {
			case 'processing':
				return __('En cours', 'lumen-wp');
			case 'awaiting_validation':
				return __('À valider', 'lumen-wp');
			case 'ok':
				return __('Réussi', 'lumen-wp');
			case 'error':
				return __('Échoué', 'lumen-wp');
			default:
				return $status !== '' ? $status : __('—', 'lumen-wp');
		}
	}

	public static function filter_label(string $filter): string
	{
		switch (self::normalize_filter($filter)) {
			case 'processing':
				return __('En cours', 'lumen-wp');
			case 'awaiting_validation':
				return __('À valider', 'lumen-wp');
			case 'ok':
				return __('Réussi', 'lumen-wp');
			case 'error':
				return __('Échoué', 'lumen-wp');
			default:
				return __('Tous', 'lumen-wp');
		}
	}

	private static function normalize_filter(string $filter): string
	{
		$filter = sanitize_key($filter);

		return in_array($filter, self::FILTERS, true) ? $filter : 'all';
	}

	private static function compression_label(int $id, string $kind): string
	{
		if (! Media_Types::supports_optimize($kind)) {
			return __('N/A', 'lumen-wp');
		}

		$variants = get_post_meta($id, Plugin::META_VARIANTS, true);
		if (! is_array($variants) || $variants === []) {
			$status = (string) get_post_meta($id, Plugin::META_STATUS, true);
			if (in_array($status, ['ok', 'awaiting_validation'], true) && ! empty(Plugin::instance()->settings()['replace_original'])) {
				$mime = (string) get_post_mime_type($id);
				if (strpos($mime, 'image/webp') === 0) {
					return __('Compressé (WEBP)', 'lumen-wp');
				}
				if (strpos($mime, 'image/avif') === 0) {
					return __('Compressé (AVIF)', 'lumen-wp');
				}
			}

			return __('Non compressé', 'lumen-wp');
		}

		$keys = [];
		foreach ($variants as $key => $val) {
			if (is_string($key) && $key !== '') {
				$keys[] = strtoupper($key);
			} elseif (is_array($val) && isset($val['format'])) {
				$keys[] = strtoupper((string) $val['format']);
			}
		}
		$keys = array_values(array_unique(array_filter($keys)));
		if ($keys === []) {
			return __('Compressé', 'lumen-wp');
		}

		return sprintf(
			/* translators: %s: formats */
			__('Compressé (%s)', 'lumen-wp'),
			implode(', ', $keys)
		);
	}

	private static function ai_label(int $id, string $status): string
	{
		if ($status === 'awaiting_validation') {
			return __('Métadonnées à valider', 'lumen-wp');
		}

		$seo = get_post_meta($id, Plugin::META_SEO, true);
		if (! is_array($seo) || $seo === []) {
			return __('—', 'lumen-wp');
		}

		$alt = trim((string) ($seo['alt_text'] ?? $seo['alt_text_wcag'] ?? $seo['alt_text_seo'] ?? ''));
		if ($alt !== '') {
			return __('Métadonnées IA', 'lumen-wp');
		}

		$title = trim((string) ($seo['title'] ?? ''));

		return $title !== '' ? __('Métadonnées', 'lumen-wp') : __('—', 'lumen-wp');
	}
}
