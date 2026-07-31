<?php

declare(strict_types=1);

namespace LumenWp;

final class Pack
{
	/**
	 * @param array<int, array<string, mixed>> $variants
	 * @param array<string, string>            $seo
	 */
	public function build_and_store(int $attachment_id, array $variants, array $seo): void
	{
		$base = $this->resolve_uploads_base($attachment_id);
		$html = $this->build_gutenberg_html($variants, $seo, $base);
		$ld   = $this->build_json_ld($variants, $seo, $base);

		update_post_meta($attachment_id, Plugin::META_GUTENBERG, $html);
		update_post_meta($attachment_id, Plugin::META_JSONLD, $ld);
	}

	/**
	 * @param array<int, array<string, mixed>> $variants
	 * @param array<string, string>            $seo
	 */
	public function build_gutenberg_html(array $variants, array $seo, string $uploads_base): string
	{
		if ($variants === []) {
			return '';
		}

		$alt = esc_attr($seo['alt_text_wcag'] ?? $seo['alt_text_seo'] ?? $seo['title'] ?? $seo['slug'] ?? '');
		$largest = $this->largest_variant($variants);

		$webp_srcset = $this->srcset_for_format($variants, 'webp', $uploads_base);
		$avif_srcset = $this->srcset_for_format($variants, 'avif', $uploads_base);
		$jpeg_srcset = $this->srcset_for_format($variants, 'jpeg', $uploads_base);

		$fallback = $this->format_url($largest, 'jpeg', $uploads_base);
		if ($fallback === '') {
			$fallback = $this->format_url($largest, 'webp', $uploads_base);
		}

		$sources = '';
		if ($avif_srcset !== '') {
			$sources .= "  <source\n    type=\"image/avif\"\n    srcset=\"\n    {$avif_srcset}\"\n    sizes=\"(max-width: 768px) 100vw, 1024px\"\n  />\n";
		}
		if ($webp_srcset !== '') {
			$sources .= "  <source\n    type=\"image/webp\"\n    srcset=\"\n    {$webp_srcset}\"\n    sizes=\"(max-width: 768px) 100vw, 1024px\"\n  />\n";
		}

		$img_srcset = $jpeg_srcset !== '' ? $jpeg_srcset : $webp_srcset;

		return "<!-- wp:html -->\n<picture>\n{$sources}  <img\n    src=\"{$fallback}\"\n    srcset=\"\n    {$img_srcset}\"\n    sizes=\"(max-width: 768px) 100vw, 1024px\"\n    alt=\"{$alt}\"\n    width=\"{$largest['width']}\"\n    height=\"{$largest['height']}\"\n    loading=\"lazy\"\n    decoding=\"async\"\n  />\n</picture>\n<!-- /wp:html -->";
	}

	/**
	 * @param array<int, array<string, mixed>> $variants
	 * @param array<string, string>            $seo
	 * @return array<string, mixed>
	 */
	public function build_json_ld(array $variants, array $seo, string $uploads_base): array
	{
		if ($variants === []) {
			return [];
		}

		$largest   = $this->largest_variant($variants);
		$thumb     = $this->find_size($variants, 'thumbnail') ?? $largest;
		$content   = $this->format_url($largest, 'jpeg', $uploads_base);
		if ($content === '') {
			$content = $this->format_url($largest, 'webp', $uploads_base);
		}
		$thumb_url = $this->format_url($thumb, 'jpeg', $uploads_base);
		if ($thumb_url === '') {
			$thumb_url = $this->format_url($thumb, 'webp', $uploads_base);
		}

		$data = [
			'@context'     => 'https://schema.org',
			'@type'        => 'ImageObject',
			'name'         => $seo['title'] ?? $seo['slug'] ?? '',
			'contentUrl'   => $content,
			'thumbnailUrl' => $thumb_url,
			'width'        => (int) ($largest['width'] ?? 0),
			'height'       => (int) ($largest['height'] ?? 0),
		];

		if (! empty($seo['caption'])) {
			$data['caption'] = $seo['caption'];
		}
		$desc = (string) ($seo['description'] ?? '');
		$alt  = (string) ($seo['alt_text_seo'] ?? '');
		if ($desc !== '' || $alt !== '') {
			$data['description'] = $desc !== '' ? $desc : $alt;
		}

		return $data;
	}

	private function resolve_uploads_base(int $attachment_id): string
	{
		$settings = Plugin::instance()->settings();
		$custom   = trim((string) ($settings['site_url'] ?? ''));
		$base     = Plugin::uploads_base_url_for_attachment($attachment_id);

		if ($custom !== '') {
			$uploads = wp_upload_dir();
			$rel     = str_replace(untrailingslashit($uploads['baseurl']), '', $base);
			$custom  = untrailingslashit($custom);
			// If custom is site home, map to uploads path under it.
			$home = untrailingslashit(home_url());
			if (strpos($custom, '/wp-content/uploads') === false) {
				$uploads_path = str_replace($home, '', untrailingslashit($uploads['baseurl']));
				$base = $custom . $uploads_path . $rel;
			} else {
				$base = $custom . $rel;
			}
		}

		return untrailingslashit($base);
	}

	/**
	 * @param array<int, array<string, mixed>> $variants
	 * @return array<string, mixed>
	 */
	private function largest_variant(array $variants): array
	{
		$best = $variants[0];
		foreach ($variants as $v) {
			if ((int) ($v['width'] ?? 0) > (int) ($best['width'] ?? 0)) {
				$best = $v;
			}
		}

		return $best;
	}

	/**
	 * @param array<int, array<string, mixed>> $variants
	 * @return array<string, mixed>|null
	 */
	private function find_size(array $variants, string $key): ?array
	{
		foreach ($variants as $v) {
			if (($v['size_key'] ?? '') === $key) {
				return $v;
			}
		}

		return null;
	}

	/**
	 * @param array<int, array<string, mixed>> $variants
	 */
	private function srcset_for_format(array $variants, string $format, string $uploads_base): string
	{
		$parts = [];
		foreach ($variants as $v) {
			$url = $this->format_url($v, $format, $uploads_base);
			if ($url === '') {
				continue;
			}
			$parts[] = $url . ' ' . (int) $v['width'] . 'w';
		}

		return implode(",\n    ", $parts);
	}

	/**
	 * @param array<string, mixed> $variant
	 */
	private function format_url(array $variant, string $format, string $uploads_base): string
	{
		$files = $variant['files'] ?? [];
		if (! is_array($files) || empty($files[$format]['filename'])) {
			return '';
		}

		if (! empty($files[$format]['url'])) {
			return (string) $files[$format]['url'];
		}

		return $uploads_base . '/' . $files[$format]['filename'];
	}
}
