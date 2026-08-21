<?php

declare(strict_types=1);

namespace LumenWp;

final class Vision_Ai
{
	public const USAGE_OPTION = 'lumen_wp_ai_usage';
	public const THUMB_MAX = 1024;
	public const MODELS_CACHE_TTL = 43200; // 12 h

	public const PROVIDERS = ['none', 'mistral', 'openai', 'anthropic', 'gemini'];

	private const DEFAULT_MODELS = [
		'mistral'   => 'ministral-14b-latest',
		'openai'    => 'gpt-4o-mini',
		'anthropic' => 'claude-sonnet-4-20250514',
		'gemini'    => 'gemini-2.0-flash',
	];

	/** @var array<string, mixed>|null Decoded provider body (usage / usageMetadata). */
	private ?array $last_usage_raw = null;

	/**
	 * Catalogue modèles Vision par fournisseur (value => label).
	 * La clé vide = défaut Lumen pour ce provider.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function models_catalog(): array
	{
		return [
			'mistral' => [
				''                    => __('Défaut — Ministral 14B', 'lumen-wp'),
				'ministral-14b-latest'=> 'Ministral 14B',
				'pixtral-12b-2409'    => 'Pixtral 12B',
				'pixtral-large-latest'=> 'Pixtral Large',
			],
			'openai' => [
				''           => __('Défaut — GPT-4o mini', 'lumen-wp'),
				'gpt-4o-mini'=> 'GPT-4o mini',
				'gpt-4o'     => 'GPT-4o',
				'gpt-4.1-mini'=> 'GPT-4.1 mini',
				'gpt-4.1'    => 'GPT-4.1',
			],
			'anthropic' => [
				''                         => __('Défaut — Claude Sonnet 4', 'lumen-wp'),
				'claude-sonnet-4-20250514' => 'Claude Sonnet 4',
				'claude-3-5-sonnet-latest' => 'Claude 3.5 Sonnet',
				'claude-3-5-haiku-latest'  => 'Claude 3.5 Haiku',
				'claude-3-opus-latest'     => 'Claude 3 Opus',
			],
			'gemini' => [
				''                    => __('Défaut — Gemini 2.0 Flash', 'lumen-wp'),
				'gemini-2.0-flash'    => 'Gemini 2.0 Flash',
				'gemini-2.0-flash-lite'=> 'Gemini 2.0 Flash Lite',
				'gemini-1.5-flash'    => 'Gemini 1.5 Flash',
				'gemini-1.5-pro'      => 'Gemini 1.5 Pro',
			],
		];
	}

	/**
	 * Catalogue code + modèles distants en cache (si disponibles).
	 *
	 * @return array<string, string> id => label
	 */
	public static function models_for_select(string $provider): array
	{
		$catalog = self::models_catalog();
		$base    = $catalog[$provider] ?? ['' => __('Choisir d’abord un fournisseur', 'lumen-wp')];
		if ($provider === 'none' || ! isset($catalog[$provider])) {
			return $base;
		}

		$merged = $base;
		$remote = self::cached_remote_models($provider);
		foreach ($remote as $id => $label) {
			$id = (string) $id;
			if ($id === '' || isset($merged[$id])) {
				continue;
			}
			$merged[$id] = (string) $label;
		}

		return $merged;
	}

	/**
	 * @return list<string>
	 */
	public static function allowed_model_ids(): array
	{
		$ids = [''];
		foreach (array_keys(self::models_catalog()) as $provider) {
			foreach (array_keys(self::models_for_select($provider)) as $id) {
				$ids[] = (string) $id;
			}
		}

		return array_values(array_unique($ids));
	}

	/**
	 * @return array<string, string>
	 */
	public static function cached_remote_models(string $provider): array
	{
		$cache = get_transient(self::models_cache_key($provider));
		if (! is_array($cache) || empty($cache['models']) || ! is_array($cache['models'])) {
			return [];
		}

		$out = [];
		foreach ($cache['models'] as $id => $label) {
			$id = sanitize_text_field((string) $id);
			if ($id === '') {
				continue;
			}
			$out[$id] = sanitize_text_field((string) $label);
		}

		return $out;
	}

	/**
	 * @return array{fetched_at: string, source: string}|null
	 */
	public static function models_cache_meta(string $provider): ?array
	{
		$cache = get_transient(self::models_cache_key($provider));
		if (! is_array($cache) || empty($cache['fetched_at'])) {
			return null;
		}

		return [
			'fetched_at' => (string) $cache['fetched_at'],
			'source'     => (string) ($cache['source'] ?? 'api'),
		];
	}

	/**
	 * Fetch / refresh remote vision models for a provider.
	 *
	 * @return array{ok: bool, models: array<string, string>, fetched_at: string, message: string, from_cache: bool}
	 */
	public static function refresh_remote_models(string $provider, string $api_key = '', bool $force = false): array
	{
		$provider = strtolower($provider);
		if (! in_array($provider, ['mistral', 'openai', 'anthropic', 'gemini'], true)) {
			return [
				'ok'         => false,
				'models'     => [],
				'fetched_at' => '',
				'message'    => __('Fournisseur invalide.', 'lumen-wp'),
				'from_cache' => false,
			];
		}

		if ($api_key === '') {
			$api_key = self::api_key_for($provider);
		}
		if ($api_key === '') {
			return [
				'ok'         => false,
				'models'     => self::cached_remote_models($provider),
				'fetched_at' => (string) (self::models_cache_meta($provider)['fetched_at'] ?? ''),
				'message'    => __('Clé API manquante pour actualiser les modèles.', 'lumen-wp'),
				'from_cache' => true,
			];
		}

		if (! $force) {
			$cached = get_transient(self::models_cache_key($provider));
			if (is_array($cached) && ! empty($cached['models']) && is_array($cached['models'])) {
				return [
					'ok'         => true,
					'models'     => self::cached_remote_models($provider),
					'fetched_at' => (string) ($cached['fetched_at'] ?? ''),
					'message'    => __('Liste en cache.', 'lumen-wp'),
					'from_cache' => true,
				];
			}
		}

		try {
			$models = self::fetch_remote_models($provider, $api_key);
		} catch (\Throwable $e) {
			return [
				'ok'         => false,
				'models'     => self::cached_remote_models($provider),
				'fetched_at' => (string) (self::models_cache_meta($provider)['fetched_at'] ?? ''),
				'message'    => $e->getMessage(),
				'from_cache' => true,
			];
		}

		$fetched_at = gmdate('c');
		set_transient(
			self::models_cache_key($provider),
			[
				'fetched_at' => $fetched_at,
				'source'     => 'api',
				'models'     => $models,
			],
			self::MODELS_CACHE_TTL
		);

		return [
			'ok'         => true,
			'models'     => $models,
			'fetched_at' => $fetched_at,
			'message'    => __('Modèles actualisés depuis l’API.', 'lumen-wp'),
			'from_cache' => false,
		];
	}

	private static function models_cache_key(string $provider): string
	{
		return 'lumen_wp_ai_models_' . $provider;
	}

	/**
	 * @return array<string, string>
	 */
	private static function fetch_remote_models(string $provider, string $api_key): array
	{
		switch ($provider) {
			case 'openai':
				return self::fetch_openai_models($api_key);
			case 'mistral':
				return self::fetch_mistral_models($api_key);
			case 'anthropic':
				return self::fetch_anthropic_models($api_key);
			case 'gemini':
				return self::fetch_gemini_models($api_key);
			default:
				return [];
		}
	}

	/**
	 * @return array<string, string>
	 */
	private static function fetch_openai_models(string $api_key): array
	{
		$data = self::http_json_get(
			'https://api.openai.com/v1/models',
			[
				'Authorization' => 'Bearer ' . $api_key,
			]
		);
		$list = is_array($data['data'] ?? null) ? $data['data'] : [];
		$out  = [];
		foreach ($list as $row) {
			if (! is_array($row)) {
				continue;
			}
			$id = (string) ($row['id'] ?? '');
			if (! self::is_openai_vision_model($id)) {
				continue;
			}
			$out[$id] = $id;
		}
		ksort($out);

		return $out;
	}

	private static function is_openai_vision_model(string $id): bool
	{
		$id = strtolower($id);
		if ($id === '' || strpos($id, 'realtime') !== false || strpos($id, 'audio') !== false) {
			return false;
		}
		if (preg_match('/(whisper|tts|embedding|dall-e|davinci|babbage|moderation|transcribe|search)/', $id)) {
			return false;
		}

		return (bool) preg_match('/^(gpt-4o|gpt-4\.1|gpt-4\.5|chatgpt-4o|o[1-9])/', $id);
	}

	/**
	 * @return array<string, string>
	 */
	private static function fetch_mistral_models(string $api_key): array
	{
		$data = self::http_json_get(
			'https://api.mistral.ai/v1/models',
			[
				'Authorization' => 'Bearer ' . $api_key,
			]
		);
		$list = is_array($data['data'] ?? null) ? $data['data'] : (is_array($data) && isset($data[0]) ? $data : []);
		$out  = [];
		foreach ($list as $row) {
			if (! is_array($row)) {
				continue;
			}
			$id = (string) ($row['id'] ?? '');
			if ($id === '') {
				continue;
			}
			$caps   = is_array($row['capabilities'] ?? null) ? $row['capabilities'] : [];
			$vision = ! empty($caps['vision']);
			$name   = strtolower($id . ' ' . (string) ($row['name'] ?? ''));
			if (! $vision && ! preg_match('/(pixtral|ministral|vision)/', $name)) {
				continue;
			}
			$label = trim((string) ($row['name'] ?? ''));
			$out[$id] = $label !== '' ? $label : $id;
		}
		ksort($out);

		return $out;
	}

	/**
	 * @return array<string, string>
	 */
	private static function fetch_anthropic_models(string $api_key): array
	{
		$data = self::http_json_get(
			'https://api.anthropic.com/v1/models?limit=100',
			[
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			]
		);
		$list = is_array($data['data'] ?? null) ? $data['data'] : [];
		$out  = [];
		foreach ($list as $row) {
			if (! is_array($row)) {
				continue;
			}
			$id = (string) ($row['id'] ?? '');
			if ($id === '' || stripos($id, 'claude') === false) {
				continue;
			}
			// Les Claude chat récents gèrent la vision ; on exclut les variants non chat.
			if (preg_match('/(embed|computer-use-only)/i', $id)) {
				continue;
			}
			$label = trim((string) ($row['display_name'] ?? $row['name'] ?? ''));
			$out[$id] = $label !== '' ? $label : $id;
		}
		ksort($out);

		return $out;
	}

	/**
	 * @return array<string, string>
	 */
	private static function fetch_gemini_models(string $api_key): array
	{
		$url  = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode($api_key) . '&pageSize=100';
		$data = self::http_json_get($url, []);
		$list = is_array($data['models'] ?? null) ? $data['models'] : [];
		$out  = [];
		foreach ($list as $row) {
			if (! is_array($row)) {
				continue;
			}
			$name = (string) ($row['name'] ?? '');
			$id   = preg_replace('#^models/#', '', $name) ?: '';
			if ($id === '') {
				continue;
			}
			$methods = is_array($row['supportedGenerationMethods'] ?? null)
				? $row['supportedGenerationMethods']
				: [];
			if (! in_array('generateContent', $methods, true)) {
				continue;
			}
			$blob = strtolower($id . ' ' . (string) ($row['displayName'] ?? '') . ' ' . (string) ($row['description'] ?? ''));
			if (preg_match('/(embed|aqa|imagen|tts|robotics|learnlm)/', $blob)) {
				continue;
			}
			// Vision / multimodal Gemini.
			if (! preg_match('/(gemini|flash|pro)/', $blob)) {
				continue;
			}
			$label = trim((string) ($row['displayName'] ?? ''));
			$out[$id] = $label !== '' ? $label : $id;
		}
		ksort($out);

		return $out;
	}

	/**
	 * @param array<string, string> $headers
	 * @return array<string, mixed>
	 */
	private static function http_json_get(string $url, array $headers): array
	{
		$args = [
			'timeout' => 20,
			'headers' => array_merge(
				[
					'Accept' => 'application/json',
				],
				$headers
			),
		];
		$response = wp_remote_get($url, $args);
		if (is_wp_error($response)) {
			throw new \RuntimeException($response->get_error_message());
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);
		$data = json_decode($body, true);
		if ($code === 401 || $code === 403) {
			throw new \RuntimeException(__('Clé API refusée par le fournisseur.', 'lumen-wp'));
		}
		if ($code === 429) {
			throw new \RuntimeException(__('Rate limit fournisseur — réessayez plus tard.', 'lumen-wp'));
		}
		if ($code < 200 || $code >= 300 || ! is_array($data)) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: HTTP status */
					__('Impossible de lister les modèles (HTTP %d).', 'lumen-wp'),
					$code
				)
			);
		}

		return $data;
	}

	/**
	 * @return array{
	 *   seo: array<string, string>,
	 *   rate_limited: bool,
	 *   error?: string,
	 *   warning?: string,
	 *   tokens?: array{
	 *     prompt: ?int,
	 *     completion: ?int,
	 *     total: ?int,
	 *     source: 'api'|'estimate'|'none',
	 *     provider: ?string
	 *   }
	 * }
	 */
	public function enrich(int $attachment_id, array $fallback = []): array
	{
		$this->last_usage_raw = null;

		$seo = new Seo();
		if ($fallback === []) {
			$fallback = $seo->build_from_filename($attachment_id);
		}

		$provider = self::active_provider();
		if ($provider === 'none') {
			return [
				'seo'          => $fallback,
				'rate_limited' => false,
				'error'        => __('Aucun fournisseur IA configuré.', 'lumen-wp'),
				'tokens'       => self::empty_tokens(null),
			];
		}

		$api_key = self::api_key_for($provider);
		if ($api_key === '') {
			return [
				'seo'          => $fallback,
				'rate_limited' => false,
				'error'        => sprintf(
					/* translators: %s: provider name */
					__('Clé API %s manquante.', 'lumen-wp'),
					self::provider_label($provider)
				),
				'tokens'       => self::empty_tokens(null),
			];
		}

		if (self::budget_reached()) {
			return [
				'seo'          => $fallback,
				'rate_limited' => false,
				'error'        => __('Budget mensuel d’appels IA atteint (réglages Lumen).', 'lumen-wp'),
				'tokens'       => self::empty_tokens(null),
			];
		}

		try {
			$kind  = Media_Types::kind($attachment_id);
			if (! Media_Types::supports_ai($kind)) {
				return [
					'seo'          => $fallback,
					'rate_limited' => false,
					'error'        => __('L’IA Vision n’est pas disponible pour ce type de média.', 'lumen-wp'),
					'tokens'       => self::empty_tokens(null),
				];
			}

			$slug  = (string) ($fallback['slug'] ?? 'media');
			$model = self::model_for($provider);
			$thumb = $this->build_thumb($attachment_id);
			$text_only = false;

			if ($thumb === null) {
				if ($kind === Media_Types::KIND_PDF) {
					// Sans aperçu (Imagick/GS/PDF protégé) : SEO via le nom de fichier.
					$text_only = true;
					$raw       = $this->dispatch_text_only($provider, $api_key, $model, $slug, $attachment_id);
				} elseif ($kind === Media_Types::KIND_VIDEO) {
					throw new \RuntimeException(__('Impossible d’obtenir une vignette vidéo pour l’IA.', 'lumen-wp'));
				} else {
					throw new \RuntimeException(__('Impossible de préparer l’aperçu pour l’IA.', 'lumen-wp'));
				}
			} else {
				$raw = $this->dispatch($provider, $api_key, $model, $thumb, $slug, $kind);
			}

			$parsed = $this->parse_metadata($raw);
			$merged = $seo->merge_seo_fields($fallback, $parsed);
			$merged['metadata_source'] = $text_only ? ($provider . '_filename') : $provider;

			self::record_usage($provider, false, '');

			$tokens = self::tokens_from_response($this->last_usage_raw ?? []);
			if (($tokens['source'] ?? 'none') === 'none' || empty($tokens['total'])) {
				$prompt_approx = $this->system_prompt($slug, $kind);
				if ($text_only) {
					$file     = get_attached_file($attachment_id);
					$filename = is_string($file) ? basename($file) : $slug . '.pdf';
					$prompt_approx = $this->system_prompt_filename($slug, $filename);
				}
				$tokens = self::estimate_tokens($prompt_approx, (string) $raw);
			}
			$tokens['provider'] = $provider;

			$out = [
				'seo'          => $merged,
				'rate_limited' => false,
				'tokens'       => $tokens,
			];
			if ($text_only) {
				$out['warning'] = $this->pdf_preview_failure_message($attachment_id)
					. ' '
					. __('Suggestion basée sur le nom de fichier (sans Vision).', 'lumen-wp');
			}

			return $out;
		} catch (Vision_Rate_Limit_Exception $e) {
			self::record_usage($provider, true, $e->getMessage());

			return [
				'seo'          => $fallback,
				'rate_limited' => true,
				'error'        => $e->getMessage(),
				'tokens'       => $this->tokens_best_effort($provider),
			];
		} catch (\Throwable $e) {
			self::record_usage($provider, false, $e->getMessage());

			return [
				'seo'          => $fallback,
				'rate_limited' => false,
				'error'        => $e->getMessage(),
				'tokens'       => $this->tokens_best_effort($provider),
			];
		}
	}

	/**
	 * @param array<string, mixed> $data Decoded API body
	 * @return array{prompt: ?int, completion: ?int, total: ?int, source: 'api'|'estimate'|'none'}
	 */
	private static function tokens_from_response(array $data): array
	{
		$none = [
			'prompt'     => null,
			'completion' => null,
			'total'      => null,
			'source'     => 'none',
		];

		$to_int = static function ($value): ?int {
			if (is_int($value)) {
				return $value;
			}
			if (is_float($value) || (is_string($value) && is_numeric($value))) {
				return (int) $value;
			}

			return null;
		};

		$pack = static function (?int $prompt, ?int $completion, ?int $total) use ($none): array {
			if ($prompt === null && $completion === null && $total === null) {
				return $none;
			}
			if ($total === null && $prompt !== null && $completion !== null) {
				$total = $prompt + $completion;
			}

			return [
				'prompt'     => $prompt,
				'completion' => $completion,
				'total'      => $total,
				'source'     => 'api',
			];
		};

		$usage = is_array($data['usage'] ?? null) ? $data['usage'] : null;
		if ($usage !== null) {
			// OpenAI / Mistral chat: usage.prompt_tokens, completion_tokens, total_tokens
			$prompt     = $to_int($usage['prompt_tokens'] ?? null);
			$completion = $to_int($usage['completion_tokens'] ?? null);
			$total      = $to_int($usage['total_tokens'] ?? null);
			$packed     = $pack($prompt, $completion, $total);
			if ($packed['source'] === 'api') {
				return $packed;
			}

			// Anthropic: usage.input_tokens, output_tokens
			$prompt     = $to_int($usage['input_tokens'] ?? null);
			$completion = $to_int($usage['output_tokens'] ?? null);
			$packed     = $pack($prompt, $completion, null);
			if ($packed['source'] === 'api') {
				return $packed;
			}
		}

		// Gemini: usageMetadata.promptTokenCount, candidatesTokenCount, totalTokenCount
		$meta = is_array($data['usageMetadata'] ?? null) ? $data['usageMetadata'] : null;
		if ($meta !== null) {
			$prompt     = $to_int($meta['promptTokenCount'] ?? null);
			$completion = $to_int($meta['candidatesTokenCount'] ?? null);
			$total      = $to_int($meta['totalTokenCount'] ?? null);
			$packed     = $pack($prompt, $completion, $total);
			if ($packed['source'] === 'api') {
				return $packed;
			}
		}

		return $none;
	}

	/**
	 * @return array{prompt: ?int, completion: ?int, total: ?int, source: 'estimate'}
	 */
	private static function estimate_tokens(string $prompt, string $completion): array
	{
		$p = (int) max(1, (int) ceil(strlen($prompt) / 4));
		$c = (int) max(1, (int) ceil(strlen($completion) / 4));

		return [
			'prompt'     => $p,
			'completion' => $c,
			'total'      => $p + $c,
			'source'     => 'estimate',
		];
	}

	/**
	 * @return array{prompt: ?int, completion: ?int, total: ?int, source: 'none', provider: ?string}
	 */
	public static function empty_tokens(?string $provider = null): array
	{
		return [
			'prompt'     => null,
			'completion' => null,
			'total'      => null,
			'source'     => 'none',
			'provider'   => $provider,
		];
	}

	/**
	 * @return array{prompt: ?int, completion: ?int, total: ?int, source: 'api'|'estimate'|'none', provider: ?string}
	 */
	private function tokens_best_effort(string $provider): array
	{
		$tokens = self::tokens_from_response($this->last_usage_raw ?? []);
		if (($tokens['source'] ?? 'none') === 'none' || empty($tokens['total'])) {
			return self::empty_tokens($provider);
		}
		$tokens['provider'] = $provider;

		return $tokens;
	}

	public static function active_provider(): string
	{
		$settings = Plugin::instance()->settings();
		$provider = strtolower((string) ($settings['ai_provider'] ?? 'none'));

		if (! in_array($provider, self::PROVIDERS, true)) {
			$provider = 'none';
		}

		// Migration douce.
		if ($provider === 'none' && self::has_stored_key('mistral')) {
			return 'mistral';
		}

		return $provider;
	}

	public static function provider_label(string $provider): string
	{
		$map = [
			'none'      => '—',
			'mistral'   => 'Mistral',
			'openai'    => 'OpenAI',
			'anthropic' => 'Anthropic',
			'gemini'    => 'Google Gemini',
		];

		return $map[$provider] ?? $provider;
	}

	public static function api_key_for(string $provider): string
	{
		$settings = Plugin::instance()->settings();
		$key_map  = [
			'mistral'   => 'mistral_api_key',
			'openai'    => 'openai_api_key',
			'anthropic' => 'anthropic_api_key',
			'gemini'    => 'gemini_api_key',
		];
		$field = $key_map[$provider] ?? '';
		if ($field === '') {
			return '';
		}

		return Api_Key_Encryption::decrypt((string) ($settings[$field] ?? ''));
	}

	public static function has_stored_key(string $provider): bool
	{
		$settings = Plugin::instance()->settings();
		$key_map  = [
			'mistral'   => 'mistral_api_key',
			'openai'    => 'openai_api_key',
			'anthropic' => 'anthropic_api_key',
			'gemini'    => 'gemini_api_key',
		];
		$field = $key_map[$provider] ?? '';

		return $field !== '' && Api_Key_Encryption::has_stored_key((string) ($settings[$field] ?? ''));
	}

	public static function model_for(string $provider): string
	{
		$settings = Plugin::instance()->settings();
		$custom   = trim((string) ($settings['ai_model'] ?? ''));
		$allowed  = array_keys(self::models_for_select($provider));
		if ($custom !== '' && in_array($custom, $allowed, true)) {
			return $custom;
		}

		return self::DEFAULT_MODELS[$provider] ?? '';
	}

	public static function is_configured(): bool
	{
		$provider = self::active_provider();

		return $provider !== 'none' && self::api_key_for($provider) !== '';
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function usage(): array
	{
		$defaults = [
			'total_calls'  => 0,
			'month_key'    => gmdate('Y-m'),
			'calls_month'  => 0,
			'by_provider'  => [
				'mistral'   => 0,
				'openai'    => 0,
				'anthropic' => 0,
				'gemini'    => 0,
			],
			'rate_limits'  => 0,
			'last_error'   => '',
			'last_call_at' => '',
		];

		$stored = get_option(self::USAGE_OPTION, []);
		if (! is_array($stored)) {
			$stored = [];
		}

		$usage = array_merge($defaults, $stored);
		if (! is_array($usage['by_provider'] ?? null)) {
			$usage['by_provider'] = $defaults['by_provider'];
		} else {
			$usage['by_provider'] = array_merge($defaults['by_provider'], $usage['by_provider']);
		}

		$month = gmdate('Y-m');
		if (($usage['month_key'] ?? '') !== $month) {
			$usage['month_key']   = $month;
			$usage['calls_month'] = 0;
			update_option(self::USAGE_OPTION, $usage, false);
		}

		return $usage;
	}

	public static function reset_usage(): void
	{
		delete_option(self::USAGE_OPTION);
	}

	public static function budget_reached(): bool
	{
		$budget = (int) (Plugin::instance()->settings()['ai_budget_month'] ?? 0);
		if ($budget <= 0) {
			return false;
		}

		$usage = self::usage();

		return (int) $usage['calls_month'] >= $budget;
	}

	public static function record_usage(string $provider, bool $rate_limited, string $error): void
	{
		$usage = self::usage();
		$usage['total_calls']  = (int) $usage['total_calls'] + 1;
		$usage['calls_month']  = (int) $usage['calls_month'] + 1;
		$usage['last_call_at'] = gmdate('c');
		if (! isset($usage['by_provider'][$provider])) {
			$usage['by_provider'][$provider] = 0;
		}
		$usage['by_provider'][$provider] = (int) $usage['by_provider'][$provider] + 1;
		if ($rate_limited) {
			$usage['rate_limits'] = (int) $usage['rate_limits'] + 1;
		}
		// Toujours écraser : un succès doit effacer l’ancienne erreur affichée au dashboard.
		$usage['last_error'] = $error;
		update_option(self::USAGE_OPTION, $usage, false);
	}

	/**
	 * @return array{data_url: string, mime: string, base64: string}|null
	 */
	private function build_thumb(int $attachment_id): ?array
	{
		$kind = Media_Types::kind($attachment_id);

		if ($kind === Media_Types::KIND_PDF || $kind === Media_Types::KIND_VIDEO) {
			$preview = $this->resolve_document_preview($attachment_id, $kind);
			if ($preview === null) {
				return null;
			}

			return $this->thumb_from_image_file($preview['path'], $preview['mime'], ! empty($preview['temp']));
		}

		$file = get_attached_file($attachment_id);
		if (! is_string($file) || ! is_readable($file)) {
			throw new \RuntimeException(__('Impossible de lire l’image', 'lumen-wp'));
		}

		$mime = (string) get_post_mime_type($attachment_id) ?: 'image/jpeg';

		return $this->thumb_from_image_file($file, $mime, false);
	}

	private function pdf_preview_failure_message(int $attachment_id): string
	{
		$file = get_attached_file($attachment_id);
		$has_imagick = class_exists('\Imagick');
		$has_gs      = $this->find_ghostscript() !== null;

		if (! is_string($file) || ! is_readable($file)) {
			return __('Impossible de lire le fichier PDF.', 'lumen-wp');
		}

		if (! $has_imagick && ! $has_gs) {
			return __('Impossible de générer un aperçu PDF pour l’IA (Imagick ou Ghostscript requis).', 'lumen-wp');
		}

		return __('Impossible de générer un aperçu PDF pour l’IA (PDF illisible, protégé, ou moteur d’aperçu en échec).', 'lumen-wp');
	}

	/**
	 * @return array{path: string, mime: string, temp?: bool}|null
	 */
	private function resolve_document_preview(int $attachment_id, string $kind): ?array
	{
		$wp_preview = $this->wp_attachment_preview_file($attachment_id);
		if ($wp_preview !== null) {
			return $wp_preview;
		}

		$file = get_attached_file($attachment_id);
		if (! is_string($file) || ! is_readable($file)) {
			return null;
		}

		if ($kind === Media_Types::KIND_PDF) {
			$imagick = $this->imagick_pdf_preview($file);
			if ($imagick !== null) {
				return $imagick;
			}

			return $this->ghostscript_pdf_preview($file);
		}

		if ($kind === Media_Types::KIND_VIDEO) {
			return $this->ffmpeg_video_frame($file);
		}

		return null;
	}

	/**
	 * Aperçu WordPress (PDF/vidéo avec tailles générées).
	 *
	 * @return array{path: string, mime: string, temp?: bool}|null
	 */
	private function wp_attachment_preview_file(int $attachment_id): ?array
	{
		$meta = wp_get_attachment_metadata($attachment_id);
		if (! is_array($meta) || empty($meta['sizes']) || ! is_array($meta['sizes'])) {
			return null;
		}

		$file = get_attached_file($attachment_id);
		if (! is_string($file) || $file === '') {
			return null;
		}

		$base_dir = dirname($file);
		$prefer   = ['large', 'medium_large', 'medium', 'thumbnail'];
		$candidates = [];
		foreach ($prefer as $size) {
			if (! empty($meta['sizes'][$size]['file'])) {
				$candidates[] = (string) $meta['sizes'][$size]['file'];
			}
		}
		foreach ($meta['sizes'] as $size_meta) {
			if (! empty($size_meta['file'])) {
				$candidates[] = (string) $size_meta['file'];
			}
		}

		foreach ($candidates as $rel) {
			$path = $base_dir . '/' . ltrim(str_replace('\\', '/', $rel), '/');
			if (! is_readable($path)) {
				continue;
			}
			$mime = wp_check_filetype($path);
			$mime_type = (string) ($mime['type'] ?? '');
			if ($mime_type === '' || strpos($mime_type, 'image/') !== 0 || $mime_type === 'image/svg+xml') {
				continue;
			}

			return ['path' => $path, 'mime' => $mime_type];
		}

		return null;
	}

	/**
	 * @return array{path: string, mime: string, temp: bool}|null
	 */
	private function imagick_pdf_preview(string $pdf_path): ?array
	{
		if (! class_exists('\Imagick')) {
			return null;
		}

		try {
			$imagick = new \Imagick();
			$imagick->setResolution(144, 144);
			$imagick->readImage($pdf_path . '[0]');
			$imagick->setIteratorIndex(0);
			$imagick->setImageBackgroundColor('white');
			if (defined('\Imagick::ALPHACHANNEL_REMOVE')) {
				$imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
			}
			$imagick->setImageFormat('jpeg');
			$imagick->setImageCompressionQuality(85);

			$tmp = tempnam(\get_temp_dir(), 'lumen-pdf');
			if ($tmp === false) {
				$imagick->clear();
				$imagick->destroy();

				return null;
			}
			$out = $tmp . '.jpg';
			@unlink($tmp);
			$imagick->writeImage($out);
			$imagick->clear();
			$imagick->destroy();

			if (! is_readable($out) || (int) filesize($out) < 32) {
				@unlink($out);

				return null;
			}

			return ['path' => $out, 'mime' => 'image/jpeg', 'temp' => true];
		} catch (\Throwable $e) {
			return null;
		}
	}

	/**
	 * Fallback LocalWP / serveurs sans extension Imagick.
	 *
	 * @return array{path: string, mime: string, temp: bool}|null
	 */
	private function ghostscript_pdf_preview(string $pdf_path): ?array
	{
		if (! function_exists('exec')) {
			return null;
		}

		$gs = $this->find_ghostscript();
		if ($gs === null) {
			return null;
		}

		$tmp = tempnam(\get_temp_dir(), 'lumen-pdf-gs');
		if ($tmp === false) {
			return null;
		}
		$out = $tmp . '.jpg';
		@unlink($tmp);

		$cmd = sprintf(
			'%s -dNOPAUSE -dBATCH -dSAFER -sDEVICE=jpeg -dFirstPage=1 -dLastPage=1 -r144 -dJPEGQ=85 -sOutputFile=%s %s 2>/dev/null',
			escapeshellarg($gs),
			escapeshellarg($out),
			escapeshellarg($pdf_path)
		);
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec($cmd, $output, $code);

		if ($code !== 0 || ! is_readable($out) || (int) filesize($out) < 32) {
			@unlink($out);

			return null;
		}

		return ['path' => $out, 'mime' => 'image/jpeg', 'temp' => true];
	}

	private function find_ghostscript(): ?string
	{
		$found = $this->find_binary(['gs']);
		if ($found !== null) {
			return $found;
		}

		$candidates = [
			'/usr/bin/gs',
			'/usr/local/bin/gs',
			'/opt/homebrew/bin/gs',
		];

		$home = getenv('HOME');
		if (is_string($home) && $home !== '') {
			$globs = glob($home . '/.config/Local/lightning-services/php-*/bin/linux/ghostscript/bin/gs') ?: [];
			rsort($globs);
			foreach ($globs as $path) {
				$candidates[] = $path;
			}
		}

		foreach ($candidates as $path) {
			if (is_string($path) && $path !== '' && is_executable($path)) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * @return array{path: string, mime: string, temp: bool}|null
	 */
	private function ffmpeg_video_frame(string $video_path): ?array
	{
		if (! function_exists('exec')) {
			return null;
		}

		$ffmpeg = $this->find_binary(['ffmpeg']);
		if ($ffmpeg === null) {
			return null;
		}

		$tmp = tempnam(\get_temp_dir(), 'lumen-vid');
		if ($tmp === false) {
			return null;
		}
		$out = $tmp . '.jpg';
		@unlink($tmp);

		$cmd = sprintf(
			'%s -y -ss 00:00:01 -i %s -frames:v 1 -q:v 3 %s 2>/dev/null',
			escapeshellarg($ffmpeg),
			escapeshellarg($video_path),
			escapeshellarg($out)
		);
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec($cmd, $output, $code);

		if ($code !== 0 || ! is_readable($out) || filesize($out) < 32) {
			@unlink($out);
			// Retry at t=0.
			$cmd0 = sprintf(
				'%s -y -ss 00:00:00 -i %s -frames:v 1 -q:v 3 %s 2>/dev/null',
				escapeshellarg($ffmpeg),
				escapeshellarg($video_path),
				escapeshellarg($out)
			);
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			exec($cmd0, $output, $code);
			if ($code !== 0 || ! is_readable($out) || filesize($out) < 32) {
				@unlink($out);

				return null;
			}
		}

		return ['path' => $out, 'mime' => 'image/jpeg', 'temp' => true];
	}

	/**
	 * @param list<string> $names
	 */
	private function find_binary(array $names): ?string
	{
		if (! function_exists('shell_exec')) {
			return null;
		}

		foreach ($names as $name) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
			$path = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
			if ($path !== '' && is_executable($path)) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * @return array{data_url: string, mime: string, base64: string}
	 */
	private function thumb_from_image_file(string $file, string $mime, bool $cleanup): array
	{
		$editor = \wp_get_image_editor($file);

		try {
			if (is_wp_error($editor)) {
				$bytes = file_get_contents($file);
				if ($bytes === false) {
					throw new \RuntimeException(__('Impossible de lire l’image', 'lumen-wp'));
				}

				return [
					'data_url' => 'data:' . $mime . ';base64,' . base64_encode($bytes),
					'mime'     => $mime,
					'base64'   => base64_encode($bytes),
				];
			}

			$size = $editor->get_size();
			$w    = (int) ($size['width'] ?? 0);
			$h    = (int) ($size['height'] ?? 0);
			$max  = max($w, $h);
			if ($max > self::THUMB_MAX) {
				$editor->resize(self::THUMB_MAX, self::THUMB_MAX, false);
			}

			$tmp = tempnam(\get_temp_dir(), 'lumen-vision');
			if ($tmp === false) {
				throw new \RuntimeException(__('Impossible de préparer la miniature IA', 'lumen-wp'));
			}
			$saved = $editor->save($tmp, 'image/jpeg');
			if (is_wp_error($saved) || empty($saved['path'])) {
				@unlink($tmp);
				throw new \RuntimeException(__('Impossible de préparer la miniature IA', 'lumen-wp'));
			}

			$bytes = file_get_contents($saved['path']);
			@unlink($saved['path']);
			if ($tmp !== $saved['path']) {
				@unlink($tmp);
			}

			if ($bytes === false) {
				throw new \RuntimeException(__('Impossible de lire l’image', 'lumen-wp'));
			}

			$b64 = base64_encode($bytes);

			return [
				'data_url' => 'data:image/jpeg;base64,' . $b64,
				'mime'     => 'image/jpeg',
				'base64'   => $b64,
			];
		} finally {
			if ($cleanup) {
				@unlink($file);
			}
		}
	}

	private function system_prompt(string $slug_hint, string $kind = Media_Types::KIND_IMAGE): string
	{
		$subject = 'l\'image fournie';
		if ($kind === Media_Types::KIND_PDF) {
			$subject = 'l\'aperçu / première page du document PDF fourni';
		} elseif ($kind === Media_Types::KIND_VIDEO) {
			$subject = 'la vignette / image extraite de la vidéo fournie';
		}

		return 'Tu es expert SEO, accessibilité web (WCAG 2.2) et rédaction WordPress en français.
Analyse ' . $subject . ' et réponds UNIQUEMENT avec un objet JSON valide (sans markdown), avec exactement ces clés :
- "title" : titre média court
- "alt_text_seo" : alt orienté mots-clés naturels (max 125 caractères)
- "alt_text_wcag" : description accessible de ce que voit un utilisateur non voyant (max 150 caractères)
- "alt_text_short" : variante très courte pour interfaces denses (max 60 caractères)
- "caption" : légende éditoriale avec une voix engageante (1 phrase)
- "description" : description média WordPress (1 à 2 phrases)
Important : ne préfixe PAS le nom du site WordPress dans ces champs — Lumen l\'ajoute ensuite automatiquement.
Contexte slug fichier : "' . $slug_hint . '".';
	}

	private function system_prompt_filename(string $slug_hint, string $filename): string
	{
		return 'Tu es expert SEO et rédaction WordPress en français.
Aucun aperçu visuel n’est disponible. Propose des métadonnées à partir du nom de fichier PDF uniquement.
Réponds UNIQUEMENT avec un objet JSON valide (sans markdown), clés exactes :
- "title", "alt_text_seo", "alt_text_wcag", "alt_text_short", "caption", "description"
(mêmes contraintes de longueur que pour une image : SEO ≤125, WCAG ≤150, court ≤60).
Nettoie le nom (tirets, underscores, extensions, domaines) en titre humain.
Ne préfixe PAS le nom du site WordPress.
Slug : "' . $slug_hint . '". Fichier : "' . $filename . '".';
	}

	/**
	 * @param array{data_url: string, mime: string, base64: string} $thumb
	 */
	private function dispatch(string $provider, string $api_key, string $model, array $thumb, string $slug, string $kind = Media_Types::KIND_IMAGE): string
	{
		switch ($provider) {
			case 'openai':
				return $this->call_openai($api_key, $model, $thumb, $slug, $kind);
			case 'anthropic':
				return $this->call_anthropic($api_key, $model, $thumb, $slug, $kind);
			case 'gemini':
				return $this->call_gemini($api_key, $model, $thumb, $slug, $kind);
			case 'mistral':
			default:
				return $this->call_mistral($api_key, $model, $thumb, $slug, $kind);
		}
	}

	private function dispatch_text_only(
		string $provider,
		string $api_key,
		string $model,
		string $slug,
		int $attachment_id
	): string {
		$file = get_attached_file($attachment_id);
		$filename = is_string($file) ? basename($file) : $slug . '.pdf';
		$system = $this->system_prompt_filename($slug, $filename);
		$user   = 'Génère le JSON SEO pour ce document PDF WordPress francophone.';

		switch ($provider) {
			case 'openai':
				$data = $this->http_json(
					'https://api.openai.com/v1/chat/completions',
					[
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $api_key,
					],
					[
						'model'           => $model,
						'temperature'     => 0.35,
						'max_tokens'      => 700,
						'response_format' => ['type' => 'json_object'],
						'messages'        => [
							['role' => 'system', 'content' => $system],
							['role' => 'user', 'content' => $user],
						],
					],
					'OpenAI'
				);
				$content = $data['choices'][0]['message']['content'] ?? null;
				break;
			case 'anthropic':
				$data = $this->http_json(
					'https://api.anthropic.com/v1/messages',
					[
						'Content-Type'      => 'application/json',
						'x-api-key'         => $api_key,
						'anthropic-version' => '2023-06-01',
					],
					[
						'model'      => $model,
						'max_tokens' => 700,
						'system'     => $system,
						'messages'   => [
							['role' => 'user', 'content' => $user],
						],
					],
					'Anthropic'
				);
				$blocks  = $data['content'] ?? [];
				$content = '';
				if (is_array($blocks)) {
					foreach ($blocks as $block) {
						if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
							$content .= $block['text'];
						}
					}
				}
				break;
			case 'gemini':
				$data = $this->http_json(
					'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($api_key),
					['Content-Type' => 'application/json'],
					[
						'system_instruction' => [
							'parts' => [['text' => $system]],
						],
						'contents' => [
							[
								'role'  => 'user',
								'parts' => [['text' => $user]],
							],
						],
						'generationConfig' => [
							'temperature'      => 0.35,
							'maxOutputTokens'  => 700,
							'responseMimeType' => 'application/json',
						],
					],
					'Gemini'
				);
				$content = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
				break;
			case 'mistral':
			default:
				$data = $this->http_json(
					'https://api.mistral.ai/v1/chat/completions',
					[
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $api_key,
					],
					[
						'model'           => $model,
						'temperature'     => 0.35,
						'max_tokens'      => 700,
						'response_format' => ['type' => 'json_object'],
						'messages'        => [
							['role' => 'system', 'content' => $system],
							['role' => 'user', 'content' => $user],
						],
					],
					'Mistral'
				);
				$content = $data['choices'][0]['message']['content'] ?? null;
				break;
		}

		if (! is_string($content) || $content === '') {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: provider label */
					__('Réponse %s vide', 'lumen-wp'),
					self::provider_label($provider)
				)
			);
		}

		return $content;
	}

	/**
	 * @param array{data_url: string, mime: string, base64: string} $thumb
	 */
	private function call_mistral(string $api_key, string $model, array $thumb, string $slug, string $kind = Media_Types::KIND_IMAGE): string
	{
		$body = [
			'model'           => $model,
			'temperature'     => 0.35,
			'max_tokens'      => 700,
			'response_format' => ['type' => 'json_object'],
			'messages'        => [
				['role' => 'system', 'content' => $this->system_prompt($slug, $kind)],
				[
					'role'    => 'user',
					'content' => [
						['type' => 'text', 'text' => 'Décris cette image pour un site WordPress francophone et remplis le JSON demandé.'],
						['type' => 'image_url', 'image_url' => $thumb['data_url']],
					],
				],
			],
		];

		$data = $this->http_json(
			'https://api.mistral.ai/v1/chat/completions',
			[
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			],
			$body,
			'Mistral'
		);

		$content = $data['choices'][0]['message']['content'] ?? null;
		if (! is_string($content) || $content === '') {
			throw new \RuntimeException(__('Réponse Mistral vide', 'lumen-wp'));
		}

		return $content;
	}

	/**
	 * @param array{data_url: string, mime: string, base64: string} $thumb
	 */
	private function call_openai(string $api_key, string $model, array $thumb, string $slug, string $kind = Media_Types::KIND_IMAGE): string
	{
		$body = [
			'model'           => $model,
			'temperature'     => 0.35,
			'max_tokens'      => 700,
			'response_format' => ['type' => 'json_object'],
			'messages'        => [
				['role' => 'system', 'content' => $this->system_prompt($slug, $kind)],
				[
					'role'    => 'user',
					'content' => [
						['type' => 'text', 'text' => 'Décris cette image pour un site WordPress francophone et remplis le JSON demandé.'],
						['type' => 'image_url', 'image_url' => ['url' => $thumb['data_url']]],
					],
				],
			],
		];

		$data = $this->http_json(
			'https://api.openai.com/v1/chat/completions',
			[
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			],
			$body,
			'OpenAI'
		);

		$content = $data['choices'][0]['message']['content'] ?? null;
		if (! is_string($content) || $content === '') {
			throw new \RuntimeException(__('Réponse OpenAI vide', 'lumen-wp'));
		}

		return $content;
	}

	/**
	 * @param array{data_url: string, mime: string, base64: string} $thumb
	 */
	private function call_anthropic(string $api_key, string $model, array $thumb, string $slug, string $kind = Media_Types::KIND_IMAGE): string
	{
		$body = [
			'model'      => $model,
			'max_tokens' => 700,
			'system'     => $this->system_prompt($slug, $kind),
			'messages'   => [
				[
					'role'    => 'user',
					'content' => [
						[
							'type'   => 'image',
							'source' => [
								'type'       => 'base64',
								'media_type' => $thumb['mime'],
								'data'       => $thumb['base64'],
							],
						],
						[
							'type' => 'text',
							'text' => 'Décris cette image pour un site WordPress francophone et remplis le JSON demandé.',
						],
					],
				],
			],
		];

		$data = $this->http_json(
			'https://api.anthropic.com/v1/messages',
			[
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			],
			$body,
			'Anthropic'
		);

		$blocks = $data['content'] ?? [];
		$text   = '';
		if (is_array($blocks)) {
			foreach ($blocks as $block) {
				if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
					$text .= $block['text'];
				}
			}
		}
		if ($text === '') {
			throw new \RuntimeException(__('Réponse Anthropic vide', 'lumen-wp'));
		}

		return $text;
	}

	/**
	 * @param array{data_url: string, mime: string, base64: string} $thumb
	 */
	private function call_gemini(string $api_key, string $model, array $thumb, string $slug, string $kind = Media_Types::KIND_IMAGE): string
	{
		$url  = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($api_key);
		$body = [
			'system_instruction' => [
				'parts' => [['text' => $this->system_prompt($slug, $kind)]],
			],
			'contents' => [
				[
					'role'  => 'user',
					'parts' => [
						['text' => 'Décris cette image pour un site WordPress francophone et remplis le JSON demandé.'],
						[
							'inline_data' => [
								'mime_type' => $thumb['mime'],
								'data'      => $thumb['base64'],
							],
						],
					],
				],
			],
			'generationConfig' => [
				'temperature'     => 0.35,
				'maxOutputTokens' => 700,
				'responseMimeType'=> 'application/json',
			],
		];

		$data = $this->http_json($url, ['Content-Type' => 'application/json'], $body, 'Gemini');

		$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
		if (! is_string($text) || $text === '') {
			throw new \RuntimeException(__('Réponse Gemini vide', 'lumen-wp'));
		}

		return $text;
	}

	/**
	 * @param array<string, string> $headers
	 * @param array<string, mixed>  $body
	 * @return array<string, mixed>
	 */
	private function http_json(string $url, array $headers, array $body, string $label): array
	{
		$response = \wp_remote_post(
			$url,
			[
				'timeout' => 60,
				'headers' => $headers,
				'body'    => wp_json_encode($body),
			]
		);

		if (is_wp_error($response)) {
			throw new \RuntimeException($response->get_error_message());
		}

		$code = (int) \wp_remote_retrieve_response_code($response);
		$raw  = (string) \wp_remote_retrieve_body($response);
		$data = json_decode($raw, true);
		if (! is_array($data)) {
			$data = [];
		}
		$this->last_usage_raw = $data;

		if ($this->is_rate_limit($code, $data)) {
			throw new Vision_Rate_Limit_Exception(
				sprintf(
					/* translators: %s: provider label */
					__('Limite de requêtes %s atteinte', 'lumen-wp'),
					$label
				)
			);
		}

		if ($code === 401 || $code === 403) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: provider label */
					__('Clé API %s invalide ou refusée', 'lumen-wp'),
					$label
				)
			);
		}

		if ($code < 200 || $code >= 300) {
			$msg = $data['error']['message'] ?? $data['message'] ?? $data['detail'] ?? sprintf('Erreur API (%d)', $code);
			if (is_array($msg)) {
				$msg = wp_json_encode($msg) ?: 'Erreur API';
			}
			throw new \RuntimeException((string) $msg);
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function is_rate_limit(int $code, array $body): bool
	{
		if ($code === 429) {
			return true;
		}

		$raw = strtolower(wp_json_encode($body) ?: '');

		return (bool) preg_match('/rate.?limit|quota|too many|resource.?exhausted|capacity|limit exceeded/i', $raw);
	}

	/**
	 * @return array<string, string>
	 */
	private function parse_metadata(string $content): array
	{
		$raw    = trim($content);
		$parsed = json_decode($raw, true);
		if (! is_array($parsed) && preg_match('/\{[\s\S]*\}/', $raw, $m)) {
			$parsed = json_decode($m[0], true);
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
}

final class Vision_Rate_Limit_Exception extends \RuntimeException
{
}
