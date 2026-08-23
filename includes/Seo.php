<?php

declare(strict_types=1);

namespace LumenWp;

final class Seo
{
	/**
	 * @return array{
	 *   slug: string,
	 *   title: string,
	 *   alt_text_seo: string,
	 *   alt_text_wcag: string,
	 *   alt_text_short: string,
	 *   caption: string,
	 *   description: string,
	 *   alt_text: string,
	 *   metadata_source: string
	 * }
	 */
	public function build_from_filename(int $attachment_id): array
	{
		$file = get_attached_file($attachment_id);
		$base = is_string($file) ? pathinfo($file, PATHINFO_FILENAME) : (string) get_the_title($attachment_id);
		$human = $this->humanize_filename($base);
		$slug  = $this->slugify($base);

		$meta = [
			'slug'            => $slug,
			'title'           => $human,
			'alt_text_seo'    => $human,
			'alt_text_wcag'   => $human,
			'alt_text_short'  => $human,
			// Légende / description : dérivées du nom (utile surtout sans IA : SVG, etc.).
			'caption'         => $human,
			'description'     => sprintf(
				/* translators: %s: humanized media title from filename */
				__('Média « %s ».', 'lumen-wp'),
				$human
			),
			'metadata_source' => 'filename',
		];

		return $this->apply_site_title_prefix($meta);
	}

	/**
	 * Apply SEO fields to the attachment post + meta.
	 *
	 * @param array<string, string> $seo
	 */
	public function apply_to_attachment(int $attachment_id, array $seo, bool $overwrite_empty_only = false): void
	{
		$seo['title']          = sanitize_text_field((string) ($seo['title'] ?? ''));
		$seo['alt_text_seo']   = sanitize_text_field((string) ($seo['alt_text_seo'] ?? ''));
		$seo['alt_text_wcag']  = sanitize_text_field((string) ($seo['alt_text_wcag'] ?? ''));
		$seo['alt_text_short'] = sanitize_text_field((string) ($seo['alt_text_short'] ?? ''));
		$seo['caption']        = sanitize_textarea_field((string) ($seo['caption'] ?? ''));
		$seo['description']    = sanitize_textarea_field((string) ($seo['description'] ?? ''));

		$alt = $seo['alt_text'] ?? ($seo['alt_text_wcag'] ?? $seo['alt_text_seo'] ?? '');

		if (! $overwrite_empty_only || get_post_meta($attachment_id, '_wp_attachment_image_alt', true) === '') {
			update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
		}

		$postarr = ['ID' => $attachment_id];
		$current = get_post($attachment_id);

		if ($current instanceof \WP_Post) {
			if (! $overwrite_empty_only || $current->post_title === '' || $current->post_title === $current->post_name) {
				if (! empty($seo['title'])) {
					$postarr['post_title'] = $seo['title'];
				}
			}
			if (! $overwrite_empty_only || $current->post_excerpt === '') {
				if (isset($seo['caption'])) {
					$postarr['post_excerpt'] = $seo['caption'];
				}
			}
			if (! $overwrite_empty_only || $current->post_content === '') {
				if (isset($seo['description'])) {
					$postarr['post_content'] = $seo['description'];
				}
			}
		}

		if (count($postarr) > 1) {
			wp_update_post($postarr);
		}

		update_post_meta($attachment_id, Plugin::META_SEO, $seo);
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_pending_seo(int $attachment_id): array
	{
		$pending = get_post_meta($attachment_id, Plugin::META_SEO_PENDING, true);
		if (! is_array($pending)) {
			$pending = get_post_meta($attachment_id, Plugin::META_SEO, true);
		}
		if (! is_array($pending)) {
			return [];
		}

		$out = [];
		foreach ($pending as $k => $v) {
			$out[(string) $k] = is_scalar($v) ? (string) $v : '';
		}

		return $out;
	}

	/**
	 * @param array<string, string> $overrides
	 */
	public function approve_pending(int $attachment_id, array $overrides = []): bool
	{
		$seo = self::get_pending_seo($attachment_id);
		if ($seo === []) {
			return false;
		}

		foreach (['title', 'alt_text', 'alt_text_seo', 'alt_text_wcag', 'alt_text_short', 'caption', 'description'] as $key) {
			if (array_key_exists($key, $overrides) && is_string($overrides[$key])) {
				$seo[$key] = $overrides[$key];
			}
		}
		if (isset($overrides['alt']) && is_string($overrides['alt'])) {
			$seo['alt_text']      = $overrides['alt'];
			$seo['alt_text_wcag'] = $overrides['alt'];
			$seo['alt_text_seo']  = $overrides['alt'];
		}

		$this->apply_to_attachment($attachment_id, $seo, false);
		delete_post_meta($attachment_id, Plugin::META_SEO_PENDING);
		update_post_meta($attachment_id, Plugin::META_STATUS, 'ok');

		$variants = get_post_meta($attachment_id, Plugin::META_VARIANTS, true);
		if (is_array($variants) && $variants !== []) {
			(new Pack())->build_and_store($attachment_id, $variants, $seo);
		}

		if (Media_Types::kind($attachment_id) === Media_Types::KIND_VIDEO) {
			Video_Schema::build_and_store($attachment_id, $seo);
		}

		return true;
	}

	public function reject_pending(int $attachment_id): bool
	{
		$status = (string) get_post_meta($attachment_id, Plugin::META_STATUS, true);
		if ($status !== 'awaiting_validation' && get_post_meta($attachment_id, Plugin::META_SEO_PENDING, true) === '') {
			return false;
		}

		delete_post_meta($attachment_id, Plugin::META_SEO_PENDING);
		update_post_meta($attachment_id, Plugin::META_STATUS, 'ok');

		return true;
	}

	/**
	 * Enrich SEO via le fournisseur Vision configuré.
	 *
	 * @param array<string, string> $fallback
	 * @return array{seo: array<string, string>, rate_limited: bool, error?: string}
	 */
	public function enrich_with_ai(int $attachment_id, array $fallback = []): array
	{
		return (new Vision_Ai())->enrich($attachment_id, $fallback);
	}

	/**
	 * @deprecated Utiliser enrich_with_ai().
	 *
	 * @param array<string, string> $fallback
	 * @return array{seo: array<string, string>, rate_limited: bool, error?: string}
	 */
	public function enrich_with_mistral(int $attachment_id, array $fallback = []): array
	{
		return $this->enrich_with_ai($attachment_id, $fallback);
	}

	/**
	 * @param array<string, string> $base
	 * @param array<string, string> $incoming
	 * @return array<string, string>
	 */
	public function merge_seo_fields(array $base, array $incoming): array
	{
		foreach (['title', 'alt_text_seo', 'alt_text_wcag', 'alt_text_short', 'caption', 'description'] as $key) {
			if (! empty($incoming[$key])) {
				$base[$key] = $incoming[$key];
			}
		}

		return $this->apply_site_title_prefix($base);
	}

	public function slugify(string $text): string
	{
		if (function_exists('iconv')) {
			$converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
			if (is_string($converted) && $converted !== '') {
				$text = $converted;
			}
		}

		$text = strtolower($text);
		$text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? 'image';
		$text = trim($text, '-');
		$text = substr($text, 0, 80);

		return $text !== '' ? $text : 'image';
	}

	public function humanize_filename(string $base): string
	{
		$text = preg_replace('/[-_]+/', ' ', $base) ?? $base;
		$text = preg_replace('/\s+/', ' ', $text) ?? $text;
		$text = trim($text);

		if (function_exists('mb_convert_case')) {
			return mb_convert_case($text, MB_CASE_TITLE, 'UTF-8');
		}

		return ucwords(strtolower($text));
	}

	private function truncate(string $text, int $max): string
	{
		if (function_exists('mb_strlen') && function_exists('mb_substr')) {
			if (mb_strlen($text) <= $max) {
				return $text;
			}

			return mb_substr($text, 0, $max);
		}

		if (strlen($text) <= $max) {
			return $text;
		}

		return substr($text, 0, $max);
	}

	private function site_title(): string
	{
		return trim((string) get_bloginfo('name'));
	}

	private function prefix_with_site_title(string $value): string
	{
		$value = trim($value);
		if ($value === '') {
			return '';
		}

		$site = $this->site_title();
		if ($site === '') {
			return $value;
		}

		$prefix = $site . ' — ';
		$starts = function_exists('mb_strpos')
			? mb_strpos($value, $prefix) === 0
			: strpos($value, $prefix) === 0;

		if ($starts) {
			return $value;
		}

		return $prefix . $value;
	}

	/**
	 * @param array<string, string> $seo
	 * @return array<string, string>
	 */
	private function apply_site_title_prefix(array $seo): array
	{
		$settings        = Plugin::instance()->settings();
		$prefix_alt      = ! empty($settings['prefix_alt_accessible']);
		$prefixed_fields = ['title', 'alt_text_seo', 'caption', 'description'];
		if ($prefix_alt) {
			$prefixed_fields[] = 'alt_text_wcag';
			$prefixed_fields[] = 'alt_text_short';
		}

		foreach ($prefixed_fields as $key) {
			if (! isset($seo[$key]) || ! is_string($seo[$key])) {
				continue;
			}
			$seo[$key] = $this->prefix_with_site_title($seo[$key]);
		}

		$seo['alt_text_seo']   = $this->truncate((string) ($seo['alt_text_seo'] ?? ''), 125);
		$seo['alt_text_wcag']  = $this->truncate((string) ($seo['alt_text_wcag'] ?? ''), 150);
		$seo['alt_text_short'] = $this->truncate((string) ($seo['alt_text_short'] ?? ''), 60);
		$seo['alt_text']       = ($seo['alt_text_wcag'] ?? '') !== ''
			? (string) $seo['alt_text_wcag']
			: (string) ($seo['alt_text_seo'] ?? '');

		return $seo;
	}
}
