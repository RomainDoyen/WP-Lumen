<?php

declare(strict_types=1);

namespace LumenWp;

final class Icon_Kit
{
	public const OPTION_ICONS = 'lumen_wp_icons';

	/** Kit tailles (comme Electron). */
	public const KIT_SIZES = [16, 32, 48, 64, 128, 256, 512];

	/** Favicons site. */
	public const SITE_SIZES = [
		'favicon-16'         => 16,
		'favicon-32'         => 32,
		'apple-touch-icon'   => 180,
		'android-chrome-192' => 192,
		'android-chrome-512' => 512,
	];

	/**
	 * @return array{dir: string, url: string}
	 */
	public function storage(): array
	{
		$uploads = wp_upload_dir();
		$dir     = trailingslashit($uploads['basedir']) . 'lumen-icons';
		$url     = trailingslashit($uploads['baseurl']) . 'lumen-icons';

		if (! is_dir($dir)) {
			wp_mkdir_p($dir);
		}

		return ['dir' => $dir, 'url' => $url];
	}

	/**
	 * Generate kit + site favicons from an uploaded temp file.
	 *
	 * @return array{
	 *   kit: list<array{size: int, filename: string, url: string, bytes: int}>,
	 *   site: array<string, array{filename: string, url: string, size: int, bytes: int}>,
	 *   zip: array{filename: string, url: string}|null,
	 *   applied: bool
	 * }
	 */
	public function generate_from_file(string $source_path, string $mime, bool $apply_site = true): array
	{
		if (! is_readable($source_path)) {
			throw new \RuntimeException(__('Fichier source illisible.', 'lumen-wp'));
		}

		$caps = Plugin::capabilities();
		if (! $caps['imagick'] && ! $caps['gd']) {
			throw new \RuntimeException(__('Imagick ou GD est requis.', 'lumen-wp'));
		}

		$storage = $this->storage();
		$this->cleanup_dir($storage['dir']);

		$kit  = [];
		$site = [];

		foreach (self::KIT_SIZES as $size) {
			$filename = 'icon-' . $size . '.png';
			$dest     = trailingslashit($storage['dir']) . $filename;
			$this->write_square_png($source_path, $mime, $dest, $size, $caps);
			$kit[] = [
				'size'     => $size,
				'filename' => $filename,
				'url'      => trailingslashit($storage['url']) . $filename,
				'bytes'    => (int) filesize($dest),
			];
		}

		foreach (self::SITE_SIZES as $key => $size) {
			$filename = $key . '.png';
			$dest     = trailingslashit($storage['dir']) . $filename;
			$this->write_square_png($source_path, $mime, $dest, $size, $caps);
			$site[$key] = [
				'filename' => $filename,
				'url'      => trailingslashit($storage['url']) . $filename,
				'size'     => $size,
				'bytes'    => (int) filesize($dest),
			];
		}

		// favicon.ico via Imagick if possible (multi-size 16+32).
		$ico = $this->try_write_ico($source_path, $mime, $storage, $caps);
		if ($ico !== null) {
			$site['favicon'] = $ico;
		}

		$zip = $this->build_zip($storage, $kit, $site);

		$data = [
			'generated_at' => gmdate('c'),
			'kit'          => $kit,
			'site'         => $site,
			'zip'          => $zip,
			'apply_site'   => $apply_site,
		];

		update_option(self::OPTION_ICONS, $data, false);

		$settings = Plugin::instance()->settings();
		if ($apply_site) {
			$settings['site_favicons'] = true;
			update_option(Plugin::OPTION_KEY, $settings, false);
			Plugin::instance()->clear_settings_cache();
		}

		return [
			'kit'     => $kit,
			'site'    => $site,
			'zip'     => $zip,
			'applied' => $apply_site,
		];
	}

	/**
	 * @return array{filename: string, url: string, size: int, bytes: int}|null
	 */
	private function try_write_ico(string $source, string $mime, array $storage, array $caps): ?array
	{
		if (! $caps['imagick']) {
			return null;
		}

		try {
			$filename = 'favicon.ico';
			$dest     = trailingslashit($storage['dir']) . $filename;
			$ico      = new \Imagick();
			foreach ([16, 32, 48] as $size) {
				$frame = new \Imagick($source);
				$this->imagick_cover($frame, $size);
				$frame->setImageFormat('png');
				$ico->addImage($frame);
				$frame->clear();
				$frame->destroy();
			}
			$ico->setFormat('ico');
			$ico->writeImages($dest, true);
			$ico->clear();
			$ico->destroy();

			if (! file_exists($dest)) {
				return null;
			}

			return [
				'filename' => $filename,
				'url'      => trailingslashit($storage['url']) . $filename,
				'size'     => 32,
				'bytes'    => (int) filesize($dest),
			];
		} catch (\Throwable $e) {
			return null;
		}
	}

	/**
	 * @param list<array{size: int, filename: string, url: string, bytes: int}> $kit
	 * @param array<string, array{filename: string, url: string, size: int, bytes: int}> $site
	 * @return array{filename: string, url: string}|null
	 */
	private function build_zip(array $storage, array $kit, array $site): ?array
	{
		if (! class_exists('ZipArchive')) {
			return null;
		}

		$filename = 'lumen-icons.zip';
		$path     = trailingslashit($storage['dir']) . $filename;
		if (file_exists($path)) {
			@unlink($path);
		}

		$zip = new \ZipArchive();
		if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			return null;
		}

		foreach ($kit as $item) {
			$file = trailingslashit($storage['dir']) . $item['filename'];
			if (is_readable($file)) {
				$zip->addFile($file, 'kit/' . $item['filename']);
			}
		}
		foreach ($site as $item) {
			$file = trailingslashit($storage['dir']) . $item['filename'];
			if (is_readable($file)) {
				$zip->addFile($file, 'site/' . $item['filename']);
			}
		}

		$zip->close();

		return [
			'filename' => $filename,
			'url'      => trailingslashit($storage['url']) . $filename . '?v=' . time(),
		];
	}

	/**
	 * @param array{imagick: bool, gd: bool, webp: bool, avif: bool} $caps
	 */
	private function write_square_png(string $source, string $mime, string $dest, int $size, array $caps): void
	{
		if ($caps['imagick']) {
			$img = new \Imagick($source);
			$this->imagick_cover($img, $size);
			$img->setImageFormat('png');
			$img->writeImage($dest);
			$img->clear();
			$img->destroy();

			return;
		}

		$this->gd_cover($source, $mime, $dest, $size);
	}

	private function imagick_cover(\Imagick $img, int $size): void
	{
		$img->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
		if (method_exists($img, 'autoOrient')) {
			@$img->autoOrient();
		}

		$w = (int) $img->getImageWidth();
		$h = (int) $img->getImageHeight();
		if ($w < 1 || $h < 1) {
			throw new \RuntimeException(__('Dimensions source invalides.', 'lumen-wp'));
		}

		$scale = max($size / $w, $size / $h);
		$nw    = (int) ceil($w * $scale);
		$nh    = (int) ceil($h * $scale);
		$img->resizeImage($nw, $nh, \Imagick::FILTER_LANCZOS, 1, true);
		$ox = (int) floor(($nw - $size) / 2);
		$oy = (int) floor(($nh - $size) / 2);
		$img->cropImage($size, $size, $ox, $oy);
		$img->setImagePage(0, 0, 0, 0);
	}

	private function gd_cover(string $source, string $mime, string $dest, int $size): void
	{
		$data = file_get_contents($source);
		if ($data === false) {
			throw new \RuntimeException(__('Impossible de lire la source.', 'lumen-wp'));
		}

		// SVG not supported by GD.
		if (stripos($mime, 'svg') !== false || preg_match('/\.svg$/i', $source)) {
			throw new \RuntimeException(__('SVG nécessite Imagick sur ce serveur.', 'lumen-wp'));
		}

		$src = @imagecreatefromstring($data);
		if ($src === false) {
			throw new \RuntimeException(__('GD ne peut pas décoder cette image.', 'lumen-wp'));
		}

		$sw = imagesx($src);
		$sh = imagesy($src);
		$scale = max($size / $sw, $size / $sh);
		$nw = (int) ceil($sw * $scale);
		$nh = (int) ceil($sh * $scale);
		$ox = (int) floor(($nw - $size) / 2);
		$oy = (int) floor(($nh - $size) / 2);

		$scaled = imagecreatetruecolor($nw, $nh);
		if ($scaled === false) {
			imagedestroy($src);
			throw new \RuntimeException(__('Échec canvas GD.', 'lumen-wp'));
		}
		imagealphablending($scaled, false);
		imagesavealpha($scaled, true);
		$transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
		imagefill($scaled, 0, 0, $transparent);
		imagealphablending($scaled, true);
		imagecopyresampled($scaled, $src, 0, 0, 0, 0, $nw, $nh, $sw, $sh);
		imagedestroy($src);

		$dst = imagecreatetruecolor($size, $size);
		if ($dst === false) {
			imagedestroy($scaled);
			throw new \RuntimeException(__('Échec canvas GD.', 'lumen-wp'));
		}
		imagealphablending($dst, false);
		imagesavealpha($dst, true);
		$transparent2 = imagecolorallocatealpha($dst, 0, 0, 0, 127);
		imagefill($dst, 0, 0, $transparent2);
		imagealphablending($dst, true);
		imagecopy($dst, $scaled, 0, 0, $ox, $oy, $size, $size);
		imagedestroy($scaled);

		$ok = imagepng($dst, $dest, 6);
		imagedestroy($dst);
		if (! $ok) {
			throw new \RuntimeException(__('Échec écriture PNG.', 'lumen-wp'));
		}
	}

	private function cleanup_dir(string $dir): void
	{
		$files = glob(trailingslashit($dir) . '*');
		if (! is_array($files)) {
			return;
		}
		foreach ($files as $file) {
			if (is_file($file)) {
				@unlink($file);
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function stored(): array
	{
		$data = get_option(self::OPTION_ICONS, []);

		return is_array($data) ? $data : [];
	}

	public function print_head_tags(): void
	{
		$settings = Plugin::instance()->settings();
		if (empty($settings['site_favicons'])) {
			return;
		}

		$data = self::stored();
		$site = $data['site'] ?? null;
		if (! is_array($site) || $site === []) {
			return;
		}

		$v = isset($data['generated_at']) ? rawurlencode((string) $data['generated_at']) : (string) time();

		if (! empty($site['favicon']['url'])) {
			echo '<link rel="icon" href="' . esc_url($site['favicon']['url'] . '?v=' . $v) . '" sizes="any">' . "\n";
		}
		if (! empty($site['favicon-32']['url'])) {
			echo '<link rel="icon" type="image/png" sizes="32x32" href="' . esc_url($site['favicon-32']['url'] . '?v=' . $v) . '">' . "\n";
		}
		if (! empty($site['favicon-16']['url'])) {
			echo '<link rel="icon" type="image/png" sizes="16x16" href="' . esc_url($site['favicon-16']['url'] . '?v=' . $v) . '">' . "\n";
		}
		if (! empty($site['apple-touch-icon']['url'])) {
			echo '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url($site['apple-touch-icon']['url'] . '?v=' . $v) . '">' . "\n";
		}
		if (! empty($site['android-chrome-192']['url'])) {
			echo '<link rel="icon" type="image/png" sizes="192x192" href="' . esc_url($site['android-chrome-192']['url'] . '?v=' . $v) . '">' . "\n";
		}
	}
}
