<?php

declare(strict_types=1);

namespace LumenWp;

/**
 * Rewrites hardcoded media URLs in post content / Elementor after original replace or restore.
 */
final class Content_Url_Rewriter
{
	/**
	 * @return array{posts: int, metas: int, replacements: int}
	 */
	public static function rewrite_paths(string $old_abs, string $new_abs): array
	{
		$empty = ['posts' => 0, 'metas' => 0, 'replacements' => 0];

		if (! self::enabled()) {
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

		$result = self::apply_pairs($pairs, basename($old_abs));
		if ($result['replacements'] > 0) {
			self::maybe_clear_elementor_cache();
		}

		return $result;
	}

	/**
	 * Also fix the attachment guid when it still points at the old file.
	 *
	 * @return array{posts: int, metas: int, replacements: int}
	 */
	public static function after_attachment_path_change(int $attachment_id, string $old_abs, string $new_abs): array
	{
		$result = self::rewrite_paths($old_abs, $new_abs);

		if (! self::enabled() || $attachment_id <= 0) {
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

		return $result;
	}

	private static function enabled(): bool
	{
		$settings = Plugin::instance()->settings();

		return ! empty($settings['rewrite_content_urls']);
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
			// JSON-escaped (Elementor _elementor_data).
			$from_json = str_replace('/', '\\/', $from);
			$to_json   = str_replace('/', '\\/', $to);
			if ($from_json !== $from) {
				$pairs[$from_json] = $to_json;
			}
		};

		$old_url = self::abs_to_url($old_abs);
		$new_url = self::abs_to_url($new_abs);
		$add($old_url, $new_url);

		// Protocol variants.
		if (strpos($old_url, 'https://') === 0) {
			$add('http://' . substr($old_url, 8), 'http://' . substr($new_url, 8));
		} elseif (strpos($old_url, 'http://') === 0) {
			$add('https://' . substr($old_url, 7), 'https://' . substr($new_url, 7));
		}

		// Protocol-relative.
		if (preg_match('#^https?:#', $old_url)) {
			$add(preg_replace('#^https?:#', '', $old_url) ?? '', preg_replace('#^https?:#', '', $new_url) ?? '');
		}

		$old_rel = self::abs_to_uploads_rel($old_abs);
		$new_rel = self::abs_to_uploads_rel($new_abs);
		if ($old_rel !== '' && $new_rel !== '') {
			$add($old_rel, $new_rel);
			$add('/' . ltrim($old_rel, '/'), '/' . ltrim($new_rel, '/'));
		}

		// Prefer longer needles first to avoid partial collisions.
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
	 * @return array{posts: int, metas: int, replacements: int}
	 */
	private static function apply_pairs(array $pairs, string $old_basename): array
	{
		global $wpdb;

		$posts_n = 0;
		$metas_n = 0;
		$repl_n  = 0;

		if ($old_basename === '') {
			return ['posts' => 0, 'metas' => 0, 'replacements' => 0];
		}

		$like = '%' . $wpdb->esc_like($old_basename) . '%';

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_status NOT IN ('trash', 'auto-draft')
				AND post_type NOT IN ('attachment', 'revision')
				AND post_content LIKE %s
				LIMIT 5000",
				$like
			)
		);

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

		$meta_sql = 'SELECT meta_id, post_id, meta_value FROM ' . $wpdb->postmeta
			. " WHERE meta_key IN ('_elementor_data','_elementor_element_cache') AND meta_value LIKE %s LIMIT 5000";
		$rows     = $wpdb->get_results($wpdb->prepare($meta_sql, $like), ARRAY_A);

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

		return [
			'posts'         => $posts_n,
			'metas'         => $metas_n,
			'replacements'  => $repl_n,
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
