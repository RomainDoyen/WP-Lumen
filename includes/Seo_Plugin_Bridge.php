<?php

declare(strict_types=1);

namespace LumenWp;

/**
 * Minimal Yoast / Rank Math / SEOPress bridge for audit + assisted fixes.
 */
final class Seo_Plugin_Bridge
{
	/** @return 'yoast'|'rank_math'|'seopress'|null */
	public function get_active_plugin(): ?string
	{
		if (defined('WPSEO_VERSION')) {
			return 'yoast';
		}
		if (defined('RANK_MATH_VERSION') || class_exists('\RankMath\Helper')) {
			return 'rank_math';
		}
		if (defined('SEOPRESS_VERSION')) {
			return 'seopress';
		}

		return null;
	}

	public function get_active_plugin_label(): string
	{
		switch ($this->get_active_plugin()) {
			case 'yoast':
				return 'Yoast SEO';
			case 'rank_math':
				return 'Rank Math';
			case 'seopress':
				return 'SEOPress';
			default:
				return __('WordPress natif', 'lumen-wp');
		}
	}

	/**
	 * @return array{title: string, description: string, focus_keyword: string}
	 */
	public function read_post_seo(int $post_id): array
	{
		$empty = ['title' => '', 'description' => '', 'focus_keyword' => ''];
		$plugin = $this->get_active_plugin();
		if ($plugin === null) {
			return $empty;
		}

		switch ($plugin) {
			case 'yoast':
				return [
					'title'         => (string) get_post_meta($post_id, '_yoast_wpseo_title', true),
					'description'   => (string) get_post_meta($post_id, '_yoast_wpseo_metadesc', true),
					'focus_keyword' => (string) get_post_meta($post_id, '_yoast_wpseo_focuskw', true),
				];
			case 'rank_math':
				return [
					'title'         => (string) get_post_meta($post_id, 'rank_math_title', true),
					'description'   => (string) get_post_meta($post_id, 'rank_math_description', true),
					'focus_keyword' => (string) get_post_meta($post_id, 'rank_math_focus_keyword', true),
				];
			case 'seopress':
				return [
					'title'         => (string) get_post_meta($post_id, '_seopress_titles_title', true),
					'description'   => (string) get_post_meta($post_id, '_seopress_titles_desc', true),
					'focus_keyword' => (string) get_post_meta($post_id, '_seopress_analysis_target_kw', true),
				];
			default:
				return $empty;
		}
	}

	/**
	 * @param array{title?: string, description?: string, focus_keyword?: string} $seo
	 */
	public function sync_post(int $post_id, array $seo): bool
	{
		$plugin = $this->get_active_plugin();
		if ($plugin === null || $post_id <= 0) {
			return false;
		}

		$title = sanitize_text_field((string) ($seo['title'] ?? ''));
		$desc  = sanitize_textarea_field((string) ($seo['description'] ?? ''));
		$focus = sanitize_text_field((string) ($seo['focus_keyword'] ?? ''));

		switch ($plugin) {
			case 'yoast':
				if ($title !== '') {
					update_post_meta($post_id, '_yoast_wpseo_title', $title);
				}
				if ($desc !== '') {
					update_post_meta($post_id, '_yoast_wpseo_metadesc', $desc);
				}
				if ($focus !== '') {
					update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus);
				}
				break;
			case 'rank_math':
				if ($title !== '') {
					update_post_meta($post_id, 'rank_math_title', $title);
				}
				if ($desc !== '') {
					update_post_meta($post_id, 'rank_math_description', $desc);
				}
				if ($focus !== '') {
					update_post_meta($post_id, 'rank_math_focus_keyword', $focus);
				}
				break;
			case 'seopress':
				if ($title !== '') {
					update_post_meta($post_id, '_seopress_titles_title', $title);
				}
				if ($desc !== '') {
					update_post_meta($post_id, '_seopress_titles_desc', $desc);
				}
				if ($focus !== '') {
					update_post_meta($post_id, '_seopress_analysis_target_kw', $focus);
				}
				break;
			default:
				return false;
		}

		return true;
	}
}
