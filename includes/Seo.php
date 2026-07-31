<?php

declare(strict_types=1);

namespace LumenWp;

final class Seo
{
	private const MISTRAL_API_URL = 'https://api.mistral.ai/v1/chat/completions';
	private const MISTRAL_VISION_MODEL = 'ministral-14b-latest';
	private const MISTRAL_THUMB_MAX = 1024;

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

		$short = $human;
		if (function_exists('mb_strlen') && mb_strlen($short) > 60) {
			$short = mb_substr($short, 0, 57) . '…';
		} elseif (strlen($short) > 60) {
			$short = substr($short, 0, 57) . '…';
		}

		$meta = [
			'slug'           => $slug,
			'title'          => $human,
			'alt_text_seo'   => $this->truncate($human, 125),
			'alt_text_wcag'  => $this->truncate($human, 150),
			'alt_text_short' => $short,
			'caption'        => '',
			'description'    => '',
			'metadata_source'=> 'filename',
		];

		$meta['alt_text'] = $meta['alt_text_wcag'] !== '' ? $meta['alt_text_wcag'] : $meta['alt_text_seo'];

		return $meta;
	}

	/**
	 * Apply SEO fields to the attachment post + meta.
	 *
	 * @param array<string, string> $seo
	 */
	public function apply_to_attachment(int $attachment_id, array $seo, bool $overwrite_empty_only = false): void
	{
		$alt = $seo['alt_text'] ?? ($seo['alt_text_wcag'] ?? $seo['alt_text_seo'] ?? '');

		if (! $overwrite_empty_only || get_post_meta($attachment_id, '_wp_attachment_image_alt', true) === '') {
			update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
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
	 * Enrich SEO via Mistral Vision. Throws on hard errors; rate-limit returns partial flag.
	 *
	 * @param array<string, string> $fallback
	 * @return array{seo: array<string, string>, rate_limited: bool, error?: string}
	 */
	public function enrich_with_mistral(int $attachment_id, array $fallback = []): array
	{
		$settings = Plugin::instance()->settings();
		$api_key  = trim((string) ($settings['mistral_api_key'] ?? ''));

		if ($api_key === '') {
			return [
				'seo'          => $fallback !== [] ? $fallback : $this->build_from_filename($attachment_id),
				'rate_limited' => false,
				'error'        => __('Clé API Mistral manquante.', 'lumen-wp'),
			];
		}

		if ($fallback === []) {
			$fallback = $this->build_from_filename($attachment_id);
		}

		try {
			$data_url = $this->build_thumb_data_url($attachment_id);
			$slug     = $fallback['slug'] ?? 'image';
			$parsed   = $this->call_mistral($api_key, $data_url, $slug);
			$merged   = $this->merge_seo($fallback, $parsed);
			$merged['metadata_source'] = 'mistral';
			$merged['alt_text'] = $merged['alt_text_wcag'] !== '' ? $merged['alt_text_wcag'] : $merged['alt_text_seo'];

			return [
				'seo'          => $merged,
				'rate_limited' => false,
			];
		} catch (Mistral_Rate_Limit_Exception $e) {
			return [
				'seo'          => $fallback,
				'rate_limited' => true,
				'error'        => $e->getMessage(),
			];
		} catch (\Throwable $e) {
			return [
				'seo'          => $fallback,
				'rate_limited' => false,
				'error'        => $e->getMessage(),
			];
		}
	}

	/**
	 * @param array<string, string> $base
	 * @param array<string, string> $incoming
	 * @return array<string, string>
	 */
	private function merge_seo(array $base, array $incoming): array
	{
		foreach (['title', 'alt_text_seo', 'alt_text_wcag', 'alt_text_short', 'caption', 'description'] as $key) {
			if (! empty($incoming[$key])) {
				$base[$key] = $incoming[$key];
			}
		}

		$base['alt_text_seo']   = $this->truncate($base['alt_text_seo'] ?? '', 125);
		$base['alt_text_wcag']  = $this->truncate($base['alt_text_wcag'] ?? '', 150);
		$base['alt_text_short'] = $this->truncate($base['alt_text_short'] ?? '', 60);

		return $base;
	}

	/**
	 * @return array<string, string>
	 */
	private function call_mistral(string $api_key, string $image_data_url, string $slug_hint): array
	{
		$system = 'Tu es expert SEO, accessibilité web (WCAG 2.2) et rédaction WordPress en français.
Analyse l\'image fournie et réponds UNIQUEMENT avec un objet JSON valide (sans markdown), avec exactement ces clés :
- "title" : titre média court
- "alt_text_seo" : alt orienté mots-clés naturels (max 125 caractères)
- "alt_text_wcag" : description accessible de ce que voit un utilisateur non voyant (max 150 caractères)
- "alt_text_short" : variante très courte pour interfaces denses (max 60 caractères)
- "caption" : légende éditoriale avec une voix engageante (1 phrase)
- "description" : description média WordPress (1 à 2 phrases)
Contexte slug fichier : "' . $slug_hint . '".';

		$body = [
			'model'           => self::MISTRAL_VISION_MODEL,
			'temperature'     => 0.35,
			'max_tokens'      => 700,
			'response_format' => ['type' => 'json_object'],
			'messages'        => [
				['role' => 'system', 'content' => $system],
				[
					'role'    => 'user',
					'content' => [
						[
							'type' => 'text',
							'text' => 'Décris cette image pour un site WordPress francophone et remplis le JSON demandé.',
						],
						[
							'type'      => 'image_url',
							'image_url' => $image_data_url,
						],
					],
				],
			],
		];

		$response = wp_remote_post(
			self::MISTRAL_API_URL,
			[
				'timeout' => 60,
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				],
				'body'    => wp_json_encode($body),
			]
		);

		if (is_wp_error($response)) {
			throw new \RuntimeException($response->get_error_message());
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$raw  = (string) wp_remote_retrieve_body($response);
		$data = json_decode($raw, true);
		if (! is_array($data)) {
			$data = [];
		}

		if ($this->is_rate_limit($code, $data)) {
			throw new Mistral_Rate_Limit_Exception(__('Limite de requêtes Mistral atteinte', 'lumen-wp'));
		}

		if ($code === 401) {
			throw new \RuntimeException(__('Clé API Mistral invalide ou expirée', 'lumen-wp'));
		}

		if ($code < 200 || $code >= 300) {
			$msg = $data['message'] ?? $data['detail'] ?? sprintf('Erreur API (%d)', $code);
			if (is_array($msg)) {
				$msg = implode(', ', $msg);
			}
			throw new \RuntimeException((string) $msg);
		}

		$content = $data['choices'][0]['message']['content'] ?? null;
		if (! is_string($content) || $content === '') {
			throw new \RuntimeException(__('Réponse Mistral vide', 'lumen-wp'));
		}

		return $this->parse_mistral_metadata($content);
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function is_rate_limit(int $code, array $body): bool
	{
		if ($code === 429) {
			return true;
		}

		$raw = strtolower(
			implode(
				' ',
				array_filter(
					[
						is_string($body['message'] ?? null) ? $body['message'] : '',
						is_string($body['detail'] ?? null) ? $body['detail'] : '',
						is_string($body['type'] ?? null) ? $body['type'] : '',
						is_string($body['code'] ?? null) ? $body['code'] : '',
					]
				)
			)
		);

		return (bool) preg_match('/rate.?limit|quota|too many|capacity|limit exceeded|service.?unavailable/i', $raw);
	}

	/**
	 * @return array<string, string>
	 */
	private function parse_mistral_metadata(string $content): array
	{
		$raw = trim($content);
		$parsed = json_decode($raw, true);
		if (! is_array($parsed)) {
			if (preg_match('/\{[\s\S]*\}/', $raw, $m)) {
				$parsed = json_decode($m[0], true);
			}
		}
		if (! is_array($parsed)) {
			throw new \RuntimeException(__('Réponse JSON illisible', 'lumen-wp'));
		}

		$pick = static function (array $parsed, array $keys): string {
			foreach ($keys as $k) {
				if (isset($parsed[$k]) && is_string($parsed[$k]) && trim($parsed[$k]) !== '') {
					return trim($parsed[$k]);
				}
			}

			return '';
		};

		return [
			'title'          => $pick($parsed, ['title', 'titre']),
			'alt_text_seo'   => $pick($parsed, ['alt_text_seo', 'alt_seo', 'altSeo']),
			'alt_text_wcag'  => $pick($parsed, ['alt_text_wcag', 'alt_wcag', 'altWcag', 'alt_text']),
			'alt_text_short' => $pick($parsed, ['alt_text_short', 'alt_short', 'altShort']),
			'caption'        => $pick($parsed, ['caption', 'legende', 'légende']),
			'description'    => $pick($parsed, ['description', 'desc']),
		];
	}

	private function build_thumb_data_url(int $attachment_id): string
	{
		$file = get_attached_file($attachment_id);
		if (! is_string($file) || ! is_readable($file)) {
			throw new \RuntimeException(__('Impossible de lire l’image', 'lumen-wp'));
		}

		$editor = wp_get_image_editor($file);
		if (is_wp_error($editor)) {
			$bytes = file_get_contents($file);
			if ($bytes === false) {
				throw new \RuntimeException(__('Impossible de lire l’image', 'lumen-wp'));
			}
			$mime = (string) get_post_mime_type($attachment_id) ?: 'image/jpeg';

			return 'data:' . $mime . ';base64,' . base64_encode($bytes);
		}

		$size = $editor->get_size();
		$w    = (int) ($size['width'] ?? 0);
		$h    = (int) ($size['height'] ?? 0);
		$max  = max($w, $h);
		if ($max > self::MISTRAL_THUMB_MAX) {
			$editor->resize(self::MISTRAL_THUMB_MAX, self::MISTRAL_THUMB_MAX, false);
		}

		$tmp = wp_tempnam('lumen-mistral');
		$saved = $editor->save($tmp, 'image/jpeg');
		if (is_wp_error($saved) || empty($saved['path'])) {
			throw new \RuntimeException(__('Impossible de préparer la miniature Mistral', 'lumen-wp'));
		}

		$bytes = file_get_contents($saved['path']);
		@unlink($saved['path']);
		if ($tmp !== $saved['path']) {
			@unlink($tmp);
		}

		if ($bytes === false) {
			throw new \RuntimeException(__('Impossible de lire l’image', 'lumen-wp'));
		}

		return 'data:image/jpeg;base64,' . base64_encode($bytes);
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
}

final class Mistral_Rate_Limit_Exception extends \RuntimeException
{
}
