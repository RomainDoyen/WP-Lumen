<?php

declare(strict_types=1);

namespace LumenWp;

/**
 * Rewrites hardcoded media URLs in post content / Elementor / options after original replace or restore.
 */
final class Content_Url_Rewriter
{
	/** @var list<string> */
	private const META_KEYS = [
		'_elementor_data',
		'_elementor_element_cache',
		'_elementor_page_settings',
	];

	/**
	 * @return array{posts: int, metas: int, options: int, replacements: int}
	 */
	public static function rewrite_paths(string $old_abs, string $new_abs, bool $force = false, bool $clear_elementor = true): array
	{
		$empty = ['posts' => 0, 'metas' => 0, 'options' => 0, 'replacements' => 0];

		if (! $force && ! self::enabled()) {
			return $empty;
		}

		$old_abs = self::norm_path($old_abs);
		$new_abs = self::norm_path($new_abs);
		if ($old_abs === '' || $new_abs === '' || $old_abs === $new_abs) {
			return $empty;
		}

		$pairs = self::build_pairs($old_abs, $new_abs);
		if ($pairs === []) {
			return $empty;
		}

		$result = self::apply_pairs($pairs, $old_abs);
		if ($clear_elementor && $result['replacements'] > 0) {
			self::maybe_clear_elementor_cache();
		}

		return $result;
	}

	/**
	 * Also fix the attachment guid when it still points at the old file.
	 *
	 * @return array{posts: int, metas: int, options: int, replacements: int}
	 */
	public static function after_attachment_path_change(
		int $attachment_id,
		string $old_abs,
		string $new_abs,
		bool $force = false,
		bool $clear_elementor = true
	): array {
		$result = self::rewrite_paths($old_abs, $new_abs, $force, $clear_elementor);

		if ((! $force && ! self::enabled()) || $attachment_id <= 0) {
			return $result;
		}

		$old_url = self::abs_to_url($old_abs);
		$new_url = self::abs_to_url($new_abs);
		if ($old_url === '' || $new_url === '' || $old_url === $new_url) {
			return $result;
		}

		$guid = (string) get_post_field('guid', $attachment_id);
		if ($guid !== '' && strpos($guid, $old_url) !== false) {
			$updated = str_replace($old_url, $new_url, $guid);
			if ($updated !== $guid) {
				wp_update_post(
					[
						'ID'   => $attachment_id,
						'guid' => $updated,
					]
				);
				$result['replacements']++;
			}
		}

		// Keep _wp_attachment_metadata size filenames on the new extension when files exist.
		self::sync_attachment_metadata_extensions($attachment_id, $old_abs, $new_abs);

		return $result;
	}

	/**
	 * Update attachment metadata `file` / `sizes[*].file` extensions after a path change.
	 */
	public static function sync_attachment_metadata_extensions(int $attachment_id, string $old_abs, string $new_abs): void
	{
		if ($attachment_id <= 0) {
			return;
		}

		$old_ext = strtolower((string) pathinfo($old_abs, PATHINFO_EXTENSION));
		$new_ext = strtolower((string) pathinfo($new_abs, PATHINFO_EXTENSION));
		if ($old_ext === '' || $new_ext === '' || $old_ext === $new_ext) {
			return;
		}

		$meta = wp_get_attachment_metadata($attachment_id);
		if (! is_array($meta)) {
			return;
		}

		$changed = false;
		$dir     = dirname(self::norm_path($new_abs));

		if (! empty($meta['file']) && is_string($meta['file'])) {
			$updated_file = (string) preg_replace(
				'/\.' . preg_quote($old_ext, '/') . '$/i',
				'.' . $new_ext,
				$meta['file']
			);
			if ($updated_file !== '' && $updated_file !== $meta['file']) {
				$meta['file'] = $updated_file;
				$changed      = true;
			}
		}

		if (! empty($meta['sizes']) && is_array($meta['sizes'])) {
			foreach ($meta['sizes'] as $key => $size) {
				if (! is_array($size) || empty($size['file']) || ! is_string($size['file'])) {
					continue;
				}
				$old_size_name = $size['file'];
				$new_size_name = (string) preg_replace(
					'/\.' . preg_quote($old_ext, '/') . '$/i',
					'.' . $new_ext,
					$old_size_name
				);
				if ($new_size_name === '' || $new_size_name === $old_size_name) {
					continue;
				}
				$candidate = $dir . '/' . basename($new_size_name);
				// Prefer real webp/avif size; otherwise keep pointing at full new file basename.
				if (is_readable($candidate)) {
					$meta['sizes'][$key]['file'] = basename($new_size_name);
					if (isset($meta['sizes'][$key]['mime-type'])) {
						$meta['sizes'][$key]['mime-type'] = $new_ext === 'avif' ? 'image/avif' : 'image/webp';
					}
					$changed = true;
				} else {
					$meta['sizes'][$key]['file'] = basename($new_abs);
					if (isset($meta['sizes'][$key]['mime-type'])) {
						$meta['sizes'][$key]['mime-type'] = $new_ext === 'avif' ? 'image/avif' : 'image/webp';
					}
					$changed = true;
				}
			}
		}

		if ($changed) {
			wp_update_attachment_metadata($attachment_id, $meta);
			clean_post_cache($attachment_id);
		}
	}

	/**
	 * Find replaced media whose old extension URLs still linger in content.
	 *
	 * @return array{
	 *   scanned: int,
	 *   issues: list<array{
	 *     id: int,
	 *     title: string,
	 *     old_url: string,
	 *     new_url: string,
	 *     old_missing: bool,
	 *     new_exists: bool,
	 *     refs: array{posts: int, metas: int, options: int},
	 *     edit_url: string
	 *   }>,
	 *   totals: array{issues: int, posts: int, metas: int, options: int}
	 * }
	 */
	public static function diagnose_stale_urls(int $limit = 150): array
	{
		$limit   = max(1, min(400, $limit));
		$pairs   = self::collect_rewrite_candidates($limit);
		$issues  = [];
		$t_posts = 0;
		$t_metas = 0;
		$t_opts  = 0;
		$seen    = [];

		foreach ($pairs as $pair) {
			$key = $pair['id'] . '|' . $pair['old_abs'];
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;

			$refs = self::count_references_fast(basename($pair['old_abs']));
			if ($refs['posts'] + $refs['metas'] + $refs['options'] <= 0) {
				continue;
			}

			$old_url = self::abs_to_url($pair['old_abs']);
			$new_url = self::abs_to_url($pair['new_abs']);
			$issues[] = [
				'id'          => $pair['id'],
				'title'       => $pair['title'],
				'old_url'     => $old_url,
				'new_url'     => $new_url,
				'old_missing' => ! is_readable($pair['old_abs']),
				'new_exists'  => is_readable($pair['new_abs']),
				'refs'        => $refs,
				'edit_url'    => Bulk_Queue::edit_url_for($pair['id']),
			];
			$t_posts += $refs['posts'];
			$t_metas += $refs['metas'];
			$t_opts  += $refs['options'];
		}

		return [
			'scanned' => count($pairs),
			'issues'  => $issues,
			'totals'  => [
				'issues'  => count($issues),
				'posts'   => $t_posts,
				'metas'   => $t_metas,
				'options' => $t_opts,
			],
		];
	}

	/**
	 * Force-rewrite all candidate stale URL pairs (ignores the settings checkbox).
	 *
	 * @return array{
	 *   attachments: int,
	 *   posts: int,
	 *   metas: int,
	 *   options: int,
	 *   replacements: int,
	 *   issues_remaining: int
	 * }
	 */
	public static function rewrite_all_stale(int $limit = 150): array
	{
		$pairs = self::collect_rewrite_candidates($limit);
		$sum   = ['posts' => 0, 'metas' => 0, 'options' => 0, 'replacements' => 0];
		$done  = 0;
		$seen  = [];

		foreach ($pairs as $pair) {
			$key = $pair['id'] . '|' . $pair['old_abs'];
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;

			$r = self::after_attachment_path_change($pair['id'], $pair['old_abs'], $pair['new_abs'], true, false);
			$sum['posts']        += $r['posts'];
			$sum['metas']        += $r['metas'];
			$sum['options']      += $r['options'];
			$sum['replacements'] += $r['replacements'];
			if ($r['replacements'] > 0) {
				$done++;
			}
		}

		self::maybe_clear_elementor_cache();

		$after = self::diagnose_stale_urls(min(100, $limit));

		return [
			'attachments'      => $done,
			'posts'            => $sum['posts'],
			'metas'            => $sum['metas'],
			'options'          => $sum['options'],
			'replacements'     => $sum['replacements'],
			'issues_remaining' => (int) ($after['totals']['issues'] ?? 0),
		];
	}

	private static function enabled(): bool
	{
		$settings = Plugin::instance()->settings();

		return ! empty($settings['rewrite_content_urls']);
	}

	/**
	 * Count attachment candidates for the URLs queue (lightweight).
	 */
	public static function count_candidate_attachments(): int
	{
		global $wpdb;

		$status_key = Plugin::META_STATUS;
		$backup_key = Plugin::META_ORIGINAL_BACKUP;
		$img_sql    = Media_Types::mime_where_sql([Media_Types::KIND_IMAGE], 'p');

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} b
					ON b.post_id = p.ID AND b.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} s
					ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = 'ok'
				WHERE p.post_type = 'attachment'
				  AND p.post_status = 'inherit'
				  AND {$img_sql}
				  AND (
					b.meta_id IS NOT NULL
					OR p.post_mime_type IN ('image/webp', 'image/avif')
					OR s.meta_id IS NOT NULL
				  )",
				$backup_key,
				$status_key
			)
		);
		// phpcs:enable

		return max(0, $total);
	}

	/**
	 * Next candidate attachment ID after cursor (ASC) for chunked jobs.
	 */
	public static function next_candidate_id(int $after_id): int
	{
		global $wpdb;

		$status_key = Plugin::META_STATUS;
		$backup_key = Plugin::META_ORIGINAL_BACKUP;
		$img_sql    = Media_Types::mime_where_sql([Media_Types::KIND_IMAGE], 'p');
		$after_id   = max(0, $after_id);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} b
					ON b.post_id = p.ID AND b.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} s
					ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = 'ok'
				WHERE p.post_type = 'attachment'
				  AND p.post_status = 'inherit'
				  AND {$img_sql}
				  AND (
					b.meta_id IS NOT NULL
					OR p.post_mime_type IN ('image/webp', 'image/avif')
					OR s.meta_id IS NOT NULL
				  )
				  AND p.ID > %d
				ORDER BY p.ID ASC
				LIMIT 1",
				$backup_key,
				$status_key,
				$after_id
			)
		);
		// phpcs:enable

		return max(0, $id);
	}

	/**
	 * @return list<array{id: int, title: string, old_abs: string, new_abs: string}>
	 */
	public static function pairs_for_attachment(int $attachment_id): array
	{
		$attachment_id = max(0, $attachment_id);
		if ($attachment_id <= 0) {
			return [];
		}

		$new = get_attached_file($attachment_id);
		if (! is_string($new) || $new === '' || ! is_readable($new)) {
			return [];
		}
		$new = self::norm_path($new);

		$title = (string) get_the_title($attachment_id);
		if ($title === '') {
			$title = basename($new);
		}

		$out = [];
		foreach (self::resolve_old_paths($attachment_id, $new) as $old) {
			if ($old === '' || $old === $new) {
				continue;
			}
			$old_ext = strtolower((string) pathinfo($old, PATHINFO_EXTENSION));
			$new_ext = strtolower((string) pathinfo($new, PATHINFO_EXTENSION));
			if ($old_ext === $new_ext && is_readable($old)) {
				continue;
			}

			$out[] = [
				'id'      => $attachment_id,
				'title'   => $title,
				'old_abs' => $old,
				'new_abs' => $new,
			];
		}

		return $out;
	}

	/**
	 * Diagnose a single candidate pair (for queue ticks).
	 *
	 * @param array{id: int, title: string, old_abs: string, new_abs: string} $pair
	 * @return array<string, mixed>|null
	 */
	public static function diagnose_pair(array $pair): ?array
	{
		$refs = self::count_references_fast(basename((string) ($pair['old_abs'] ?? '')));
		if ($refs['posts'] + $refs['metas'] + $refs['options'] <= 0) {
			return null;
		}

		$old_abs = (string) ($pair['old_abs'] ?? '');
		$new_abs = (string) ($pair['new_abs'] ?? '');

		return [
			'id'          => (int) ($pair['id'] ?? 0),
			'title'       => (string) ($pair['title'] ?? ''),
			'old_url'     => self::abs_to_url($old_abs),
			'new_url'     => self::abs_to_url($new_abs),
			'old_missing' => $old_abs === '' || ! is_readable($old_abs),
			'new_exists'  => $new_abs !== '' && is_readable($new_abs),
			'refs'        => $refs,
			'edit_url'    => Bulk_Queue::edit_url_for((int) ($pair['id'] ?? 0)),
		];
	}

	/** Public wrapper for end-of-job Elementor purge. */
	public static function clear_elementor_cache(): void
	{
		self::maybe_clear_elementor_cache();
	}

	/**
	 * @return list<array{id: int, title: string, old_abs: string, new_abs: string}>
	 */
	private static function collect_rewrite_candidates(int $limit): array
	{
		$limit = max(1, min(400, $limit));
		$out   = [];
		$cursor = 0;

		// Prefer ASC cursor walk (same as Url_Queue) but stop at $limit pairs.
		while (count($out) < $limit) {
			$id = self::next_candidate_id($cursor);
			if ($id <= 0) {
				break;
			}
			$cursor = $id;
			foreach (self::pairs_for_attachment($id) as $pair) {
				$out[] = $pair;
				if (count($out) >= $limit) {
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * @return list<string>
	 */
	private static function resolve_old_paths(int $attachment_id, string $current_abs): array
	{
		$backup = Original_Backup::meta($attachment_id);
		if ($backup !== null && ($backup['original_file'] ?? '') !== '') {
			$resolved = Original_Backup::resolve_path((string) $backup['original_file']);
			if ($resolved !== '') {
				return [self::norm_path($resolved)];
			}
		}

		$ext = strtolower((string) pathinfo($current_abs, PATHINFO_EXTENSION));
		if (! in_array($ext, ['webp', 'avif'], true)) {
			return [];
		}

		$guesses = [];

		// Prefer extension still present on the attachment guid (often pre-replace).
		$guid = (string) get_post_field('guid', $attachment_id);
		if (preg_match('/\.(jpe?g|png|gif)(?:\?|#|$)/i', $guid, $m)) {
			$from_guid = (string) preg_replace('/\.[^.]+$/', '.' . strtolower($m[1]), $current_abs);
			if ($from_guid !== '' && $from_guid !== $current_abs) {
				$guesses[] = self::norm_path($from_guid);
			}
		}

		foreach (['png', 'jpg', 'jpeg'] as $candidate) {
			$guess = (string) preg_replace('/\.[^.]+$/', '.' . $candidate, $current_abs);
			if ($guess === '' || $guess === $current_abs) {
				continue;
			}
			$guess = self::norm_path($guess);
			// Classic after replace: old file is gone from disk.
			if (! is_readable($guess)) {
				$guesses[] = $guess;
			}
		}

		return array_values(array_unique($guesses));
	}

	/**
	 * Fast reference counts (COUNT only — no content loading).
	 * Matches the full basename AND WordPress intermediate sizes (`stem-110x37.ext`).
	 *
	 * @return array{posts: int, metas: int, options: int}
	 */
	private static function count_references_fast(string $old_basename): array
	{
		global $wpdb;

		if ($old_basename === '') {
			return ['posts' => 0, 'metas' => 0, 'options' => 0];
		}

		[$like_full, $like_sizes] = self::like_patterns_for_basename($old_basename);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$posts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM {$wpdb->posts}
				WHERE post_status NOT IN ('trash', 'auto-draft')
				AND post_type NOT IN ('attachment', 'revision')
				AND (post_content LIKE %s OR post_content LIKE %s)",
				$like_full,
				$like_sizes
			)
		);

		$in = "'" . implode("','", array_map('esc_sql', self::META_KEYS)) . "'";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$metas = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(meta_id) FROM {$wpdb->postmeta}
				WHERE meta_key IN ($in) AND (meta_value LIKE %s OR meta_value LIKE %s)",
				$like_full,
				$like_sizes
			)
		);
		// phpcs:enable

		$opts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(option_id) FROM {$wpdb->options}
				WHERE option_name NOT LIKE %s
				  AND option_name NOT LIKE %s
				  AND (option_value LIKE %s OR option_value LIKE %s)",
				$wpdb->esc_like('_transient_') . '%',
				$wpdb->esc_like('_site_transient_') . '%',
				$like_full,
				$like_sizes
			)
		);
		// phpcs:enable

		return [
			'posts'   => $posts,
			'metas'   => $metas,
			'options' => $opts,
		];
	}

	/**
	 * @return array{0: string, 1: string} [like_full, like_sizes]
	 */
	private static function like_patterns_for_basename(string $old_basename): array
	{
		global $wpdb;

		$stem = (string) pathinfo($old_basename, PATHINFO_FILENAME);
		$ext  = strtolower((string) pathinfo($old_basename, PATHINFO_EXTENSION));

		$like_full  = '%' . $wpdb->esc_like($old_basename) . '%';
		// WP intermediates: stem-110x37.ext (stem- alone is too broad without ext anchor).
		$like_sizes = '%' . $wpdb->esc_like($stem . '-') . '%' . $wpdb->esc_like('.' . $ext) . '%';

		return [$like_full, $like_sizes];
	}

	/**
	 * @return array<string, string> old => new (longest keys first)
	 */
	private static function build_pairs(string $old_abs, string $new_abs): array
	{
		$pairs = [];

		$add = static function (string $from, string $to) use (&$pairs): void {
			if ($from === '' || $to === '' || $from === $to) {
				return;
			}
			$pairs[$from] = $to;
			$from_json    = str_replace('/', '\\/', $from);
			$to_json      = str_replace('/', '\\/', $to);
			if ($from_json !== $from) {
				$pairs[$from_json] = $to_json;
			}
		};

		$add_path_pair = static function (string $from_abs, string $to_abs) use ($add): void {
			$from_abs = self::norm_path($from_abs);
			$to_abs   = self::norm_path($to_abs);
			if ($from_abs === '' || $to_abs === '' || $from_abs === $to_abs) {
				return;
			}

			$old_url = self::abs_to_url($from_abs);
			$new_url = self::abs_to_url($to_abs);
			$add($old_url, $new_url);

			if ($old_url !== '' && strpos($old_url, 'https://') === 0) {
				$add('http://' . substr($old_url, 8), 'http://' . substr($new_url, 8));
			} elseif ($old_url !== '' && strpos($old_url, 'http://') === 0) {
				$add('https://' . substr($old_url, 7), 'https://' . substr($new_url, 7));
			}

			if ($old_url !== '' && preg_match('#^https?:#', $old_url)) {
				$add(preg_replace('#^https?:#', '', $old_url) ?? '', preg_replace('#^https?:#', '', $new_url) ?? '');
			}

			$old_rel = self::abs_to_uploads_rel($from_abs);
			$new_rel = self::abs_to_uploads_rel($to_abs);
			if ($old_rel !== '' && $new_rel !== '') {
				$add($old_rel, $new_rel);
				$add('/' . ltrim($old_rel, '/'), '/' . ltrim($new_rel, '/'));
			}
		};

		$add_path_pair($old_abs, $new_abs);

		// WordPress intermediates: logo-110x37.png → logo-110x37.webp (or full .webp fallback).
		$old_ext = strtolower((string) pathinfo($old_abs, PATHINFO_EXTENSION));
		$new_ext = strtolower((string) pathinfo($new_abs, PATHINFO_EXTENSION));
		$stem    = (string) pathinfo($old_abs, PATHINFO_FILENAME);
		$dir     = dirname($old_abs);

		if ($old_ext !== '' && $new_ext !== '' && $old_ext !== $new_ext && $stem !== '' && is_dir($dir)) {
			$pattern = $dir . '/' . $stem . '-*.' . $old_ext;
			$found   = glob($pattern) ?: [];
			foreach ($found as $old_size) {
				$old_size = self::norm_path((string) $old_size);
				if (! preg_match('/-\d+x\d+\.' . preg_quote($old_ext, '/') . '$/i', $old_size)) {
					continue;
				}
				$new_size = (string) preg_replace(
					'/\.' . preg_quote($old_ext, '/') . '$/i',
					'.' . $new_ext,
					$old_size
				);
				$target = (is_string($new_size) && $new_size !== '' && is_readable($new_size))
					? $new_size
					: $new_abs;
				$add_path_pair($old_size, $target);
			}
		}

		uksort(
			$pairs,
			static function (string $a, string $b): int {
				return strlen($b) <=> strlen($a);
			}
		);

		return $pairs;
	}

	/**
	 * @param array<string, string> $pairs
	 * @param string                 $old_abs Absolute path of the pre-replace file (full size).
	 * @return array{posts: int, metas: int, options: int, replacements: int}
	 */
	private static function apply_pairs(array $pairs, string $old_abs): array
	{
		global $wpdb;

		$posts_n = 0;
		$metas_n = 0;
		$opts_n  = 0;
		$repl_n  = 0;

		$old_basename = basename($old_abs);
		if ($old_basename === '') {
			return ['posts' => 0, 'metas' => 0, 'options' => 0, 'replacements' => 0];
		}

		[$like_full, $like_sizes] = self::like_patterns_for_basename($old_basename);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_status NOT IN ('trash', 'auto-draft')
				AND post_type NOT IN ('attachment', 'revision')
				AND (post_content LIKE %s OR post_content LIKE %s)
				LIMIT 5000",
				$like_full,
				$like_sizes
			)
		);
		// phpcs:enable

		if (is_array($post_ids)) {
			foreach ($post_ids as $post_id) {
				$post_id = (int) $post_id;
				$content = (string) get_post_field('post_content', $post_id);
				if ($content === '') {
					continue;
				}
				$updated = self::replace_all($content, $pairs);
				if ($updated !== $content) {
					$wpdb->update(
						$wpdb->posts,
						['post_content' => $updated],
						['ID' => $post_id],
						['%s'],
						['%d']
					);
					clean_post_cache($post_id);
					$posts_n++;
					$repl_n += self::count_replacements($content, $updated, $pairs);
				}
			}
		}

		$in = "'" . implode("','", array_map('esc_sql', self::META_KEYS)) . "'";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta}
				WHERE meta_key IN ($in) AND (meta_value LIKE %s OR meta_value LIKE %s)
				LIMIT 5000",
				$like_full,
				$like_sizes
			),
			ARRAY_A
		);
		// phpcs:enable

		if (is_array($rows)) {
			foreach ($rows as $row) {
				$meta_id = (int) ($row['meta_id'] ?? 0);
				$post_id = (int) ($row['post_id'] ?? 0);
				$value   = (string) ($row['meta_value'] ?? '');
				if ($meta_id <= 0 || $value === '') {
					continue;
				}
				$updated = self::replace_all($value, $pairs);
				if ($updated !== $value) {
					$wpdb->update(
						$wpdb->postmeta,
						['meta_value' => $updated],
						['meta_id' => $meta_id],
						['%s'],
						['%d']
					);
					if ($post_id > 0) {
						clean_post_cache($post_id);
					}
					$metas_n++;
					$repl_n += self::count_replacements($value, $updated, $pairs);
				}
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$opt_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_id, option_name, option_value FROM {$wpdb->options}
				WHERE option_name NOT LIKE %s
				  AND option_name NOT LIKE %s
				  AND (option_value LIKE %s OR option_value LIKE %s)
				LIMIT 2000",
				$wpdb->esc_like('_transient_') . '%',
				$wpdb->esc_like('_site_transient_') . '%',
				$like_full,
				$like_sizes
			),
			ARRAY_A
		);
		// phpcs:enable

		if (is_array($opt_rows)) {
			foreach ($opt_rows as $row) {
				$option_id = (int) ($row['option_id'] ?? 0);
				$value     = (string) ($row['option_value'] ?? '');
				if ($option_id <= 0 || $value === '') {
					continue;
				}
				$updated = self::replace_all($value, $pairs);
				if ($updated !== $value) {
					$wpdb->update(
						$wpdb->options,
						['option_value' => $updated],
						['option_id' => $option_id],
						['%s'],
						['%d']
					);
					$opts_n++;
					$repl_n += self::count_replacements($value, $updated, $pairs);
					wp_cache_delete((string) ($row['option_name'] ?? ''), 'options');
				}
			}
		}

		return [
			'posts'        => $posts_n,
			'metas'        => $metas_n,
			'options'      => $opts_n,
			'replacements' => $repl_n,
		];
	}

	/**
	 * @param array<string, string> $pairs
	 */
	private static function replace_all(string $haystack, array $pairs): string
	{
		foreach ($pairs as $from => $to) {
			if ($from !== '' && strpos($haystack, $from) !== false) {
				$haystack = str_replace($from, $to, $haystack);
			}
		}

		return $haystack;
	}

	/**
	 * @param array<string, string> $pairs
	 */
	private static function count_replacements(string $before, string $after, array $pairs): int
	{
		$total = 0;
		foreach ($pairs as $from => $to) {
			if ($from === '' || $to === '') {
				continue;
			}
			$diff = substr_count($before, $from) - substr_count($after, $from);
			if ($diff > 0) {
				$total += $diff;
			}
		}

		return $total;
	}

	private static function maybe_clear_elementor_cache(): void
	{
		if (! class_exists('\Elementor\Plugin')) {
			return;
		}
		try {
			$plugin = \Elementor\Plugin::$instance;
			if (isset($plugin->files_manager) && is_object($plugin->files_manager) && method_exists($plugin->files_manager, 'clear_cache')) {
				$plugin->files_manager->clear_cache();
			}
		} catch (\Throwable $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Optional integration — never break the pipeline.
		}
	}

	private static function abs_to_url(string $abs): string
	{
		$uploads = wp_upload_dir();
		if (! empty($uploads['error'])) {
			return '';
		}
		$basedir = trailingslashit(self::norm_path((string) $uploads['basedir']));
		$baseurl = untrailingslashit((string) $uploads['baseurl']);
		$norm    = self::norm_path($abs);

		if (strpos($norm, $basedir) !== 0) {
			return '';
		}

		$rel = ltrim(substr($norm, strlen($basedir)), '/');

		return $baseurl . '/' . $rel;
	}

	private static function abs_to_uploads_rel(string $abs): string
	{
		$uploads = wp_upload_dir();
		if (! empty($uploads['error'])) {
			return '';
		}
		$basedir = trailingslashit(self::norm_path((string) $uploads['basedir']));
		$norm    = self::norm_path($abs);
		if (strpos($norm, $basedir) !== 0) {
			return '';
		}

		return ltrim(substr($norm, strlen($basedir)), '/');
	}

	private static function norm_path(string $path): string
	{
		return str_replace('\\', '/', $path);
	}
}
