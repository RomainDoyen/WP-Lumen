<?php

declare(strict_types=1);

namespace LumenWp;

final class Video_Schema
{
	/**
	 * Build a VideoObject JSON-LD document. Empty optional keys are omitted.
	 *
	 * @param array<string, mixed> $seo
	 * @param array<string, mixed> $media contentUrl, thumbnailUrl, uploadDate, url, name, duration
	 * @return array<string, mixed>
	 */
	public static function build(array $seo, array $media = []): array
	{
		$name = trim((string) ($seo['title'] ?? ''));
		if ($name === '') {
			$name = trim((string) ($media['name'] ?? ''));
		}

		$description = trim((string) ($seo['description'] ?? ''));
		if ($description === '') {
			$description = trim((string) ($seo['caption'] ?? ''));
		}

		$content_url = (string) ($media['contentUrl'] ?? '');
		$page_url    = (string) ($media['url'] ?? '');
		if ($page_url === '') {
			$page_url = $content_url;
		}

		$data = [
			'@context'     => 'https://schema.org',
			'@type'        => 'VideoObject',
			'name'         => $name,
			'description'  => $description,
			'contentUrl'   => $content_url,
			'thumbnailUrl' => (string) ($media['thumbnailUrl'] ?? ''),
			'uploadDate'   => (string) ($media['uploadDate'] ?? ''),
			'url'          => $page_url,
		];

		$duration = (string) ($media['duration'] ?? '');
		if ($duration !== '') {
			$data['duration'] = $duration;
		}

		foreach (['name', 'description', 'contentUrl', 'thumbnailUrl', 'uploadDate', 'url', 'duration'] as $key) {
			if (! isset($data[$key]) || $data[$key] === '') {
				unset($data[$key]);
			}
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $seo
	 * @return array<string, mixed>
	 */
	public static function build_and_store(int $attachment_id, array $seo): array
	{
		$url   = function_exists('wp_get_attachment_url') ? (string) (wp_get_attachment_url($attachment_id) ?: '') : '';
		$thumb = '';
		if (function_exists('get_the_post_thumbnail_url')) {
			$thumb = (string) (get_the_post_thumbnail_url($attachment_id, 'medium') ?: '');
		}
		if ($thumb === '') {
			$thumb = self::thumbnail_fallback($attachment_id);
		}

		$post = function_exists('get_post') ? get_post($attachment_id) : null;
		$name = '';
		if ($post instanceof \WP_Post) {
			$name = (string) $post->post_title;
		}

		$upload = '';
		if ($post && function_exists('get_post_time')) {
			$upload = (string) (get_post_time('c', true, $post) ?: '');
		}

		$page = $url;
		if (function_exists('get_attachment_link')) {
			$page = (string) (get_attachment_link($attachment_id) ?: $url);
		}

		$data = self::build($seo, [
			'name'         => $name,
			'contentUrl'   => $url,
			'thumbnailUrl' => $thumb,
			'uploadDate'   => $upload,
			'url'          => $page,
			'duration'     => self::duration_iso8601($attachment_id),
		]);

		update_post_meta($attachment_id, Plugin::META_JSONLD, $data);

		return $data;
	}

	private static function thumbnail_fallback(int $attachment_id): string
	{
		if (function_exists('get_post_thumbnail_id') && function_exists('wp_get_attachment_image_url')) {
			$poster = (int) get_post_thumbnail_id($attachment_id);
			if ($poster > 0) {
				$thumb = wp_get_attachment_image_url($poster, 'medium');
				if (is_string($thumb) && $thumb !== '') {
					return $thumb;
				}
			}
		}

		if (! function_exists('wp_get_attachment_metadata')) {
			return '';
		}

		$meta = wp_get_attachment_metadata($attachment_id);
		if (! is_array($meta) || empty($meta['image'])) {
			return '';
		}

		$image = $meta['image'];
		if (! empty($image['src']) && is_string($image['src'])) {
			return $image['src'];
		}

		if (empty($image['file']) || ! is_string($image['file']) || ! function_exists('wp_get_attachment_url')) {
			return '';
		}

		$file_url = wp_get_attachment_url($attachment_id);
		if (! is_string($file_url) || $file_url === '') {
			return '';
		}

		return trailingslashit(dirname($file_url)) . basename($image['file']);
	}

	private static function duration_iso8601(int $attachment_id): string
	{
		if (! function_exists('wp_get_attachment_metadata')) {
			return '';
		}

		$meta = wp_get_attachment_metadata($attachment_id);
		if (! is_array($meta)) {
			return '';
		}

		$seconds = (int) ($meta['length'] ?? 0);
		if ($seconds <= 0) {
			return '';
		}

		$h = intdiv($seconds, 3600);
		$m = intdiv($seconds % 3600, 60);
		$s = $seconds % 60;

		$out = 'PT';
		if ($h > 0) {
			$out .= $h . 'H';
		}
		if ($m > 0) {
			$out .= $m . 'M';
		}
		if ($s > 0 || $out === 'PT') {
			$out .= $s . 'S';
		}

		return $out;
	}
}
