<?php

declare(strict_types=1);

namespace LumenWp;

final class Api_Key_Encryption
{
	public const PREFIX = 'lumen1:';
	public const CIPHER = 'AES-256-CBC';
	public const MIGRATE_OPTION = 'lumen_wp_keys_migrated';

	/** @var list<string> */
	public const KEY_FIELDS = [
		'mistral_api_key',
		'openai_api_key',
		'anthropic_api_key',
		'gemini_api_key',
	];

	public static function available(): bool
	{
		return extension_loaded('openssl')
			&& function_exists('openssl_encrypt')
			&& function_exists('openssl_decrypt');
	}

	public static function encrypt(string $plain): string
	{
		$plain = trim($plain);
		if ($plain === '') {
			return '';
		}
		if (! self::available()) {
			return $plain; // fallback plain
		}
		$key    = self::material();
		$iv_len = openssl_cipher_iv_length(self::CIPHER);
		if ($iv_len === false || $iv_len < 1) {
			return $plain;
		}
		$iv = openssl_random_pseudo_bytes($iv_len);
		$enc = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
		if ($enc === false) {
			return $plain;
		}

		return self::PREFIX . base64_encode($iv . $enc);
	}

	public static function decrypt(string $stored): string
	{
		$stored = trim($stored);
		if ($stored === '') {
			return '';
		}
		if (! self::is_encrypted($stored)) {
			return $stored; // legacy plain
		}
		if (! self::available()) {
			error_log('[Lumen] API key decrypt failed');
			return '';
		}
		$raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
		if ($raw === false) {
			error_log('[Lumen] API key decrypt failed');
			return '';
		}
		$iv_len = openssl_cipher_iv_length(self::CIPHER);
		if ($iv_len === false || strlen($raw) <= $iv_len) {
			error_log('[Lumen] API key decrypt failed');
			return '';
		}
		$iv  = substr($raw, 0, $iv_len);
		$enc = substr($raw, $iv_len);
		$out = openssl_decrypt($enc, self::CIPHER, self::material(), OPENSSL_RAW_DATA, $iv);
		if ($out === false || $out === '') {
			error_log('[Lumen] API key decrypt failed');
			return '';
		}

		return $out;
	}

	public static function is_encrypted(string $stored): bool
	{
		return strpos($stored, self::PREFIX) === 0;
	}

	public static function is_corrupt_stored(string $stored): bool
	{
		$stored = trim($stored);

		return $stored !== '' && self::is_encrypted($stored) && self::decrypt($stored) === '';
	}

	public static function has_stored_key(string $stored): bool
	{
		return self::decrypt($stored) !== '';
	}

	public static function migrate_settings_keys(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}
		if ((string) get_option(self::MIGRATE_OPTION, '') === '1') {
			return;
		}
		if (! self::available()) {
			return; // leave plain; notice elsewhere
		}

		$settings = get_option(Plugin::OPTION_KEY, []);
		if (! is_array($settings)) {
			$settings = [];
		}
		$changed = false;
		foreach (self::KEY_FIELDS as $field) {
			$val = trim((string) ($settings[$field] ?? ''));
			if ($val === '' || self::is_encrypted($val)) {
				continue;
			}
			$settings[$field] = self::encrypt($val);
			$changed          = true;
		}
		if ($changed) {
			update_option(Plugin::OPTION_KEY, $settings, false);
			Plugin::instance()->clear_settings_cache();
		}
		update_option(self::MIGRATE_OPTION, '1', false);
	}

	private static function material(): string
	{
		$a = defined('AUTH_KEY') ? (string) AUTH_KEY : '';
		$b = defined('SECURE_AUTH_KEY') ? (string) SECURE_AUTH_KEY : '';

		return hash('sha256', $a . $b, true);
	}
}
