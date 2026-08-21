<?php

declare(strict_types=1);

namespace LumenWp;

final class Server_Caps
{
	private const TRANSIENT_KEY = 'lumen_wp_server_caps';
	private const TTL = 300;

	/**
	 * @return array{
	 *   imagick: bool,
	 *   gd: bool,
	 *   webp: bool,
	 *   avif: bool,
	 *   ghostscript: bool,
	 *   ffmpeg: bool,
	 *   exec: bool,
	 *   shell_exec: bool,
	 *   openssl: bool,
	 *   action_scheduler: bool,
	 *   memory_limit: string,
	 *   memory_bytes: int
	 * }
	 */
	public static function detect(bool $bypass_cache = false): array
	{
		if (! $bypass_cache) {
			$cached = get_transient(self::TRANSIENT_KEY);
			if (is_array($cached) && self::is_complete($cached)) {
				return $cached;
			}
		}

		$limit = (string) ini_get('memory_limit');
		$out   = array_merge(self::image_caps(), [
			'ghostscript'      => self::has_ghostscript(),
			'ffmpeg'           => self::has_binary(['ffmpeg']),
			'exec'             => self::fn_enabled('exec'),
			'shell_exec'       => self::fn_enabled('shell_exec'),
			'openssl'          => Api_Key_Encryption::available(),
			'action_scheduler' => As_Bridge::available(),
			'memory_limit'     => $limit,
			'memory_bytes'     => self::parse_bytes($limit),
		]);

		set_transient(self::TRANSIENT_KEY, $out, self::TTL);

		return $out;
	}

	public static function flush(): void
	{
		delete_transient(self::TRANSIENT_KEY);
	}

	/**
	 * @param array<string, mixed> $cached
	 */
	private static function is_complete(array $cached): bool
	{
		foreach ([
			'imagick',
			'gd',
			'webp',
			'avif',
			'ghostscript',
			'ffmpeg',
			'exec',
			'shell_exec',
			'openssl',
			'action_scheduler',
			'memory_limit',
			'memory_bytes',
		] as $key) {
			if (! array_key_exists($key, $cached)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array{imagick: bool, gd: bool, webp: bool, avif: bool}
	 */
	private static function image_caps(): array
	{
		$imagick = extension_loaded('imagick') && class_exists('\Imagick');
		$gd      = extension_loaded('gd') && function_exists('imagecreatetruecolor');

		$webp = false;
		$avif = false;

		if ($imagick) {
			try {
				$formats = array_map('strtoupper', \Imagick::queryFormats());
				$webp    = in_array('WEBP', $formats, true);
				$avif    = in_array('AVIF', $formats, true);
			} catch (\Throwable $e) {
				// Keep defaults.
			}
		}

		if ($gd) {
			if (function_exists('imagewebp')) {
				$webp = true;
			}
			if (function_exists('imageavif')) {
				$avif = true;
			}
		}

		return [
			'imagick' => $imagick,
			'gd'      => $gd,
			'webp'    => $webp,
			'avif'    => $avif,
		];
	}

	private static function has_ghostscript(): bool
	{
		if (self::has_binary(['gs', 'gswin64c', 'gswin32c'])) {
			return true;
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
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<string> $names
	 */
	private static function has_binary(array $names): bool
	{
		if (! self::fn_enabled('shell_exec')) {
			return false;
		}

		foreach ($names as $name) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
			$path = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
			if ($path !== '' && is_executable($path)) {
				return true;
			}
		}

		return false;
	}

	private static function fn_enabled(string $fn): bool
	{
		if (! function_exists($fn)) {
			return false;
		}

		$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

		return ! in_array($fn, $disabled, true);
	}

	private static function parse_bytes(string $limit): int
	{
		$limit = trim($limit);
		if ($limit === '' || $limit === '-1') {
			return -1;
		}

		if (function_exists('wp_convert_hr_to_bytes')) {
			return (int) wp_convert_hr_to_bytes($limit);
		}

		$value = strtolower($limit);
		$bytes = (int) $value;
		if (strpos($value, 'g') !== false) {
			$bytes *= 1024 * 1024 * 1024;
		} elseif (strpos($value, 'm') !== false) {
			$bytes *= 1024 * 1024;
		} elseif (strpos($value, 'k') !== false) {
			$bytes *= 1024;
		}

		return $bytes;
	}
}
