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
		$json = wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (! is_string($json) || $json === '') {
			return;
		}
		echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * VideoObject JSON-LD — implemented in Task 3.
	 */
	public function emit_videos(): void
	{
	}
}
