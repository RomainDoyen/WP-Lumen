<?php

declare(strict_types=1);

namespace LumenWp;

final class Plugin
{
	private static ?self $instance = null;

	public const OPTION_KEY = 'lumen_wp_settings';

	public const META_STATUS = '_lumen_status';
	public const META_VARIANTS = '_lumen_variants';
	public const META_SEO = '_lumen_seo';
	public const META_JSONLD = '_lumen_jsonld';
	public const META_GUTENBERG = '_lumen_gutenberg';
	public const META_ERROR = '_lumen_error';

	/** @var array<string, mixed>|null */
	private ?array $settings_cache = null;

	public static function instance(): self
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct()
	{
	}

	public function init(): void
	{
		$this->autoload();

		add_action('plugins_loaded', [$this, 'load_textdomain']);
		add_action('init', [$this, 'boot']);
	}

	public function load_textdomain(): void
	{
		load_plugin_textdomain('lumen-wp', false, dirname(LUMEN_WP_BASENAME) . '/languages');
	}

	public function boot(): void
	{
		(new Hooks())->register();

		if (is_admin()) {
			(new Admin\Dashboard())->register();
			(new Admin\Bulk())->register();
			(new Admin\Icons())->register();
			(new Admin\Settings())->register();
			(new Admin\Media_Meta_Box())->register();
		}
	}

	private function autoload(): void
	{
		$files = [
			'Optimizer.php',
			'Seo.php',
			'Pack.php',
			'Icon_Kit.php',
			'Hooks.php',
			'Admin/Brand.php',
			'Admin/Dashboard.php',
			'Admin/Settings.php',
			'Admin/Bulk.php',
			'Admin/Icons.php',
			'Admin/Media_Meta_Box.php',
		];

		foreach ($files as $file) {
			$path = LUMEN_WP_PATH . 'includes/' . $file;
			if (is_readable($path)) {
				require_once $path;
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array
	{
		return [
			'formats'           => ['webp', 'jpeg'],
			'webp_quality'      => 82,
			'jpeg_quality'      => 85,
			'avif_quality'      => 65,
			'replace_original'  => true,
			'auto_on_upload'    => false,
			'auto_seo_on_upload'=> true,
			'mistral_api_key'   => '',
			'site_url'          => '',
			'site_favicons'     => false,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function settings(): array
	{
		if ($this->settings_cache !== null) {
			return $this->settings_cache;
		}

		$stored = get_option(self::OPTION_KEY, []);
		if (! is_array($stored)) {
			$stored = [];
		}

		$this->settings_cache = array_merge(self::defaults(), $stored);

		return $this->settings_cache;
	}

	public function clear_settings_cache(): void
	{
		$this->settings_cache = null;
	}

	/**
	 * @return array{imagick: bool, gd: bool, webp: bool, avif: bool}
	 */
	public static function capabilities(): array
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

	public static function attachment_is_modern_mime(int $attachment_id): bool
	{
		$mime = strtolower((string) get_post_mime_type($attachment_id));

		return in_array($mime, ['image/webp', 'image/avif'], true);
	}

	/**
	 * Traitement considéré terminé pour stats / skip / bulk.
	 * En mode remplacement : le fichier principal doit être WebP ou AVIF.
	 */
	public static function attachment_is_done(int $attachment_id): bool
	{
		$status = (string) get_post_meta($attachment_id, self::META_STATUS, true);
		if ($status !== 'ok') {
			return false;
		}

		$variants = get_post_meta($attachment_id, self::META_VARIANTS, true);
		if (! is_array($variants) || $variants === []) {
			return false;
		}

		$settings = self::instance()->settings();
		if (! empty($settings['replace_original'])) {
			return self::attachment_is_modern_mime($attachment_id);
		}

		return true;
	}

	public static function uploads_base_url_for_attachment(int $attachment_id): string
	{
		$file = get_attached_file($attachment_id);
		if (! is_string($file) || $file === '') {
			return untrailingslashit(wp_upload_dir()['url']);
		}

		$uploads = wp_upload_dir();
		$basedir = trailingslashit($uploads['basedir']);
		$baseurl = untrailingslashit($uploads['baseurl']);

		$dir = dirname($file);
		if (strpos($dir, $basedir) === 0) {
			$rel = ltrim(str_replace('\\', '/', substr($dir, strlen($basedir))), '/');

			return $rel === '' ? $baseurl : $baseurl . '/' . $rel;
		}

		return $baseurl;
	}
}
