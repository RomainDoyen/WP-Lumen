<?php

declare(strict_types=1);

namespace LumenWp;

final class Schema_Front
{
	public function register(): void
	{
		add_action('wp_head', [$this, 'emit'], 20);
	}

	public function emit(): void
	{
		$this->emit_faq();
		$this->emit_videos();
	}

	public function emit_faq(): void
	{
		if (! is_singular()) {
			return;
		}
		$settings = Plugin::instance()->settings();
		if (empty($settings['emit_faq_schema'])) {
			return;
		}
		$post_id = get_queried_object_id();
		$schema  = get_post_meta($post_id, Plugin::META_FAQ, true);
		if (! is_array($schema) || empty($schema['mainEntity'])) {
			return;
		}
		$this->echo_ld_json($schema);
	}

	public function emit_videos(): void
	{
		if (is_attachment()) {
			$this->emit_video_for_attachment(get_queried_object_id());

			return;
		}

		if (! is_singular()) {
			return;
		}

		$seen = [];
		foreach ($this->collect_video_attachment_ids(get_queried_object_id()) as $attachment_id) {
			if (isset($seen[$attachment_id])) {
				continue;
			}
			$seen[$attachment_id] = true;
			$this->emit_video_for_attachment($attachment_id);
		}
	}

	private function emit_video_for_attachment(int $attachment_id): void
	{
		if ($attachment_id <= 0) {
			return;
		}
		if (Media_Types::kind($attachment_id) !== Media_Types::KIND_VIDEO) {
			return;
		}

		$schema = get_post_meta($attachment_id, Plugin::META_JSONLD, true);
		if (! is_array($schema) || ($schema['@type'] ?? '') !== 'VideoObject') {
			return;
		}

		$this->echo_ld_json($schema);
	}

	/**
	 * @return list<int>
	 */
	private function collect_video_attachment_ids(int $post_id): array
	{
		$ids = [];

		$featured = (int) get_post_thumbnail_id($post_id);
		if ($featured > 0) {
			$ids[] = $featured;
		}

		$post    = get_post($post_id);
		$content = $post instanceof \WP_Post ? (string) $post->post_content : '';
		if ($content === '') {
			return $this->filter_video_ids($ids);
		}

		if (function_exists('has_blocks') && function_exists('parse_blocks') && has_blocks($content)) {
			$this->collect_video_ids_from_blocks(parse_blocks($content), $ids);
		}

		if (preg_match_all('/<!--\s+wp:video\b[^>]*?"id"\s*:\s*(\d+)/', $content, $block_ids)) {
			foreach ($block_ids[1] as $raw) {
				$ids[] = (int) $raw;
			}
		}

		if (preg_match_all('/wp-image-(\d+)/', $content, $image_ids)) {
			foreach ($image_ids[1] as $raw) {
				$ids[] = (int) $raw;
			}
		}

		return $this->filter_video_ids($ids);
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param list<int>                        $ids
	 */
	private function collect_video_ids_from_blocks(array $blocks, array &$ids): void
	{
		foreach ($blocks as $block) {
			$name = (string) ($block['blockName'] ?? '');
			if ($name === 'core/video') {
				$id = (int) ($block['attrs']['id'] ?? 0);
				if ($id > 0) {
					$ids[] = $id;
				}
			}
			if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
				$this->collect_video_ids_from_blocks($block['innerBlocks'], $ids);
			}
		}
	}

	/**
	 * @param list<int> $ids
	 * @return list<int>
	 */
	private function filter_video_ids(array $ids): array
	{
		$out  = [];
		$seen = [];
		foreach ($ids as $id) {
			$id = (int) $id;
			if ($id <= 0 || isset($seen[$id])) {
				continue;
			}
			if (Media_Types::kind($id) !== Media_Types::KIND_VIDEO) {
				continue;
			}
			$seen[$id] = true;
			$out[]     = $id;
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $schema
	 */
	private function echo_ld_json(array $schema): void
	{
		$json = wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (! is_string($json) || $json === '') {
			return;
		}
		echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
