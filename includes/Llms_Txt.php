<?php

declare(strict_types=1);

namespace LumenWp;

/**
 * Serve / generate llms.txt via rewrite (no ABSPATH write).
 */
final class Llms_Txt
{
	public const OPTION_CONTENT = 'lumen_wp_llms_txt';
	public const OPTION_ENABLED = 'lumen_wp_llms_txt_enabled';
	public const OPTION_UPDATED = 'lumen_wp_llms_txt_updated_at';
	public const QUERY_VAR      = 'lumen_wp_llms_txt';

	public function register(): void
	{
		add_action('init', [$this, 'add_rewrite_rules']);
		add_filter('query_vars', [$this, 'register_query_var']);
		add_action('template_redirect', [$this, 'maybe_serve'], 0);
	}

	public function add_rewrite_rules(): void
	{
		add_rewrite_rule('^llms\.txt$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
	}

	/**
	 * @param list<string> $vars
	 * @return list<string>
	 */
	public function register_query_var(array $vars): array
	{
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	public function maybe_serve(): void
	{
		if ((string) get_query_var(self::QUERY_VAR) !== '1') {
			return;
		}

		if (! $this->is_enabled()) {
			status_header(404);
			nocache_headers();
			exit;
		}

		$content = (string) get_option(self::OPTION_CONTENT, '');
		if ($content === '') {
			$content = $this->build_content();
		}

		status_header(200);
		nocache_headers();
		header('Content-Type: text/plain; charset=utf-8');
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function is_enabled(): bool
	{
		$settings = Plugin::instance()->settings();
		if (array_key_exists('llms_txt_enabled', $settings)) {
			return ! empty($settings['llms_txt_enabled']);
		}

		return (bool) get_option(self::OPTION_ENABLED, true);
	}

	public function exists(): bool
	{
		return trim((string) get_option(self::OPTION_CONTENT, '')) !== '';
	}

	public function public_url(): string
	{
		return home_url('/llms.txt');
	}

	/**
	 * @return array{success: bool, message: string, url: string, lines: int}
	 */
	public function generate(): array
	{
		if (! $this->is_enabled()) {
			return [
				'success' => false,
				'message' => __('llms.txt est désactivé dans les réglages Lumen.', 'lumen-wp'),
				'url'     => '',
				'lines'   => 0,
			];
		}

		$content = $this->build_content();
		update_option(self::OPTION_CONTENT, $content, false);
		update_option(self::OPTION_UPDATED, gmdate('c'), false);
		$this->add_rewrite_rules();
		flush_rewrite_rules(false);

		return [
			'success' => true,
			'message' => __('llms.txt disponible à l’URL publique du site.', 'lumen-wp'),
			'url'     => $this->public_url(),
			'lines'   => substr_count($content, "\n") + 1,
		];
	}

	public function build_content(): string
	{
		$lines   = [];
		$lines[] = '# ' . get_bloginfo('name');
		$lines[] = '';
		$lines[] = '> ' . __('Bonus GEO — convention communautaire (non garantie par les moteurs IA).', 'lumen-wp');
		$lines[] = '';

		$desc = get_bloginfo('description');
		if ($desc !== '') {
			$lines[] = $desc;
			$lines[] = '';
		}

		$lines[] = '## ' . __('Pages principales', 'lumen-wp');
		$lines[] = '';

		$pages = get_pages(
			[
				'sort_column' => 'menu_order',
				'number'      => 30,
				'post_status' => 'publish',
			]
		);
		foreach ($pages as $page) {
			$summary = $this->summary($page);
			$lines[] = '- [' . $page->post_title . '](' . get_permalink($page) . ')' . ($summary !== '' ? ' — ' . $summary : '');
		}

		$posts = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 15,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			]
		);
		if ($posts !== []) {
			$lines[] = '';
			$lines[] = '## ' . __('Articles récents', 'lumen-wp');
			$lines[] = '';
			foreach ($posts as $post) {
				$summary = $this->summary($post);
				$lines[] = '- [' . $post->post_title . '](' . get_permalink($post) . ')' . ($summary !== '' ? ' — ' . $summary : '');
			}
		}

		return implode("\n", $lines) . "\n";
	}

	private function summary(\WP_Post $post): string
	{
		$text = trim((string) $post->post_excerpt);
		if ($text === '') {
			$text = wp_trim_words(wp_strip_all_tags((string) $post->post_content), 18, '…');
		}
		$text = preg_replace('/\s+/u', ' ', $text) ?: '';

		return function_exists('mb_substr') ? mb_substr($text, 0, 120) : substr($text, 0, 120);
	}
}
