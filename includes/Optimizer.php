<?php

declare(strict_types=1);

namespace LumenWp;

final class Optimizer
{
	public const SIZES = [
		['key' => 'full', 'label' => 'Original', 'max' => null, 'crop' => false],
		['key' => 'large', 'label' => 'Large', 'max' => 1024, 'crop' => false],
		['key' => 'medium_large', 'label' => 'Medium large', 'max' => 768, 'crop' => false],
		['key' => 'medium', 'label' => 'Medium', 'max' => 300, 'crop' => false],
		['key' => 'thumbnail', 'label' => 'Thumbnail', 'max' => 150, 'crop' => true],
	];

	/**
	 * Process an attachment: generate format variants for each WP size.
	 *
	 * @return array{variants: array<int, array<string, mixed>>, replaced: bool}
	 *
	 * @throws \RuntimeException
	 */
	public function process_attachment(int $attachment_id): array
	{
		$file = get_attached_file($attachment_id);
		if (! is_string($file) || $file === '' || ! is_readable($file)) {
			throw new \RuntimeException(__('Fichier attachment introuvable.', 'lumen-wp'));
		}

		$mime = (string) get_post_mime_type($attachment_id);
		if (! $this->is_supported_mime($mime, $file)) {
			throw new \RuntimeException(__('Type MIME non supporté.', 'lumen-wp'));
		}

		$settings = Plugin::instance()->settings();
		$formats  = $this->resolve_formats($settings);
		if ($formats === []) {
			throw new \RuntimeException(__('Aucun format de sortie disponible.', 'lumen-wp'));
		}

		$caps = Plugin::capabilities();
		if (! $caps['imagick'] && ! $caps['gd']) {
			throw new \RuntimeException(__('Imagick ou GD est requis.', 'lumen-wp'));
		}

		$source = $this->load_source($file, $caps);
		$src_w  = (int) $source['width'];
		$src_h  = (int) $source['height'];

		$dir      = dirname($file);
		$basename = pathinfo($file, PATHINFO_FILENAME);
		$slug     = sanitize_title($basename) ?: 'image';

		$variants = [];

		foreach (self::SIZES as $size) {
			$dims = $this->compute_dims($src_w, $src_h, $size['max'], (bool) $size['crop']);
			$row  = [
				'size_key'   => $size['key'],
				'size_label' => $size['label'],
				'width'      => $dims['width'],
				'height'     => $dims['height'],
				'files'      => [],
			];

			foreach ($formats as $format) {
				$filename = sprintf(
					'%s-%s-%dx%d.%s',
					$slug,
					$size['key'],
					$dims['width'],
					$dims['height'],
					$format === 'jpeg' ? 'jpg' : $format
				);
				$dest = trailingslashit($dir) . $filename;

				$this->write_variant(
					$source,
					$dest,
					$dims['width'],
					$dims['height'],
					(bool) $size['crop'],
					$format,
					$settings,
					$caps
				);

				$row['files'][$format] = [
					'filename' => $filename,
					'path'     => $dest,
					'url'      => $this->file_to_url($dest),
				];
			}

			$variants[] = $row;
		}

		$replaced = false;
		if (! empty($settings['replace_original'])) {
			$replacement = $this->pick_replacement_source($variants[0]['files'] ?? [], $formats);
			if ($replacement !== null) {
				$replaced = $this->replace_original(
					$attachment_id,
					$file,
					$replacement['path'],
					$replacement['mime']
				);
			}
		}

		$this->destroy_source($source);

		update_post_meta($attachment_id, Plugin::META_VARIANTS, $variants);

		return [
			'variants' => $variants,
			'replaced' => $replaced,
		];
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return list<string>
	 */
	private function resolve_formats(array $settings): array
	{
		$requested = $settings['formats'] ?? ['webp', 'jpeg'];
		if (! is_array($requested)) {
			$requested = ['webp', 'jpeg'];
		}

		$caps   = Plugin::capabilities();
		$result = [];

		foreach ($requested as $format) {
			$format = strtolower((string) $format);
			if ($format === 'jpg') {
				$format = 'jpeg';
			}
			if (! in_array($format, ['webp', 'avif', 'jpeg'], true)) {
				continue;
			}
			if ($format === 'webp' && ! $caps['webp']) {
				continue;
			}
			if ($format === 'avif' && ! $caps['avif']) {
				continue;
			}
			$result[] = $format;
		}

		// Always ensure JPEG fallback when possible (Lumen pack default).
		if (! in_array('jpeg', $result, true)) {
			$result[] = 'jpeg';
		}

		return array_values(array_unique($result));
	}

	private function is_supported_mime(string $mime, string $file): bool
	{
		$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/jpg'];
		if (in_array(strtolower($mime), $allowed, true)) {
			return true;
		}

		return (bool) preg_match('/\.(jpe?g|png|webp|avif)$/i', $file);
	}

	/**
	 * @param array{imagick: bool, gd: bool, webp: bool, avif: bool} $caps
	 * @return array{engine: string, resource: mixed, width: int, height: int}
	 */
	private function load_source(string $file, array $caps): array
	{
		if ($caps['imagick']) {
			$img = new \Imagick($file);
			$img->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
			if (method_exists($img, 'autoOrient')) {
				@$img->autoOrient();
			}

			return [
				'engine'   => 'imagick',
				'resource' => $img,
				'width'    => (int) $img->getImageWidth(),
				'height'   => (int) $img->getImageHeight(),
			];
		}

		$data = file_get_contents($file);
		if ($data === false) {
			throw new \RuntimeException(__('Impossible de lire le fichier source.', 'lumen-wp'));
		}

		$gd = @imagecreatefromstring($data);
		if ($gd === false) {
			throw new \RuntimeException(__('GD ne peut pas décoder cette image.', 'lumen-wp'));
		}

		return [
			'engine'   => 'gd',
			'resource' => $gd,
			'width'    => (int) imagesx($gd),
			'height'   => (int) imagesy($gd),
		];
	}

	/**
	 * @param array{engine: string, resource: mixed, width: int, height: int} $source
	 */
	private function destroy_source(array $source): void
	{
		if ($source['engine'] === 'imagick' && $source['resource'] instanceof \Imagick) {
			$source['resource']->clear();
			$source['resource']->destroy();
		} elseif (
			$source['engine'] === 'gd'
			&& (is_resource($source['resource']) || $source['resource'] instanceof \GdImage)
		) {
			imagedestroy($source['resource']);
		}
	}

	/**
	 * @return array{width: int, height: int}
	 */
	private function compute_dims(int $src_w, int $src_h, ?int $max, bool $crop): array
	{
		if ($crop) {
			$side = min($src_w, $src_h, $max ?? 150);

			return ['width' => $side, 'height' => $side];
		}

		if ($max === null || ($src_w <= $max && $src_h <= $max)) {
			return ['width' => max(1, $src_w), 'height' => max(1, $src_h)];
		}

		$scale = $max / max($src_w, $src_h);

		return [
			'width'  => max(1, (int) round($src_w * $scale)),
			'height' => max(1, (int) round($src_h * $scale)),
		];
	}

	/**
	 * @param array{engine: string, resource: mixed, width: int, height: int} $source
	 * @param array<string, mixed>                                           $settings
	 * @param array{imagick: bool, gd: bool, webp: bool, avif: bool}         $caps
	 */
	private function write_variant(
		array $source,
		string $dest,
		int $width,
		int $height,
		bool $crop,
		string $format,
		array $settings,
		array $caps
	): void {
		if ($source['engine'] === 'imagick' && $source['resource'] instanceof \Imagick) {
			$this->write_imagick($source['resource'], $dest, $width, $height, $crop, $format, $settings);

			return;
		}

		if ($format === 'avif' && ! function_exists('imageavif')) {
			throw new \RuntimeException(__('AVIF non supporté par GD.', 'lumen-wp'));
		}

		$this->write_gd($source['resource'], $dest, $width, $height, $crop, $format, $settings);
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function write_imagick(
		\Imagick $source,
		string $dest,
		int $width,
		int $height,
		bool $crop,
		string $format,
		array $settings
	): void {
		$clone = clone $source;
		$sw    = (int) $clone->getImageWidth();
		$sh    = (int) $clone->getImageHeight();

		if ($crop) {
			$side = min($sw, $sh);
			$sx   = (int) floor(($sw - $side) / 2);
			$sy   = (int) floor(($sh - $side) / 2);
			$clone->cropImage($side, $side, $sx, $sy);
			$clone->setImagePage(0, 0, 0, 0);
			$clone->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1, true);
		} else {
			$clone->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1, true);
		}

		if ($format === 'jpeg') {
			try {
				if (method_exists($clone, 'getImageAlphaChannel') && $clone->getImageAlphaChannel()) {
					$clone->setImageBackgroundColor(new \ImagickPixel('white'));
					if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
						$clone->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
					}
					$flattened = $clone->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
					$clone->clear();
					$clone->destroy();
					$clone = $flattened;
				}
			} catch (\Throwable $e) {
				// Keep clone as-is if alpha flatten is unsupported.
			}
			$clone->setImageFormat('jpeg');
			$clone->setImageCompression(\Imagick::COMPRESSION_JPEG);
			$clone->setImageCompressionQuality((int) ($settings['jpeg_quality'] ?? 85));
		} elseif ($format === 'webp') {
			$clone->setImageFormat('webp');
			$clone->setImageCompressionQuality((int) ($settings['webp_quality'] ?? 82));
		} else {
			$clone->setImageFormat('avif');
			$clone->setImageCompressionQuality((int) ($settings['avif_quality'] ?? 65));
		}

		if (! $clone->writeImage($dest)) {
			$clone->clear();
			$clone->destroy();
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: format name */
					__('Échec écriture variante %s.', 'lumen-wp'),
					$format
				)
			);
		}
		$clone->clear();
		$clone->destroy();
	}

	/**
	 * @param \GdImage|resource    $source
	 * @param array<string, mixed> $settings
	 */
	private function write_gd($source, string $dest, int $width, int $height, bool $crop, string $format, array $settings): void
	{
		$sw = imagesx($source);
		$sh = imagesy($source);

		$dst = imagecreatetruecolor($width, $height);
		if ($dst === false) {
			throw new \RuntimeException(__('Échec création canvas GD.', 'lumen-wp'));
		}

		if ($format === 'jpeg') {
			$white = imagecolorallocate($dst, 255, 255, 255);
			imagefill($dst, 0, 0, $white);
		} else {
			imagealphablending($dst, false);
			imagesavealpha($dst, true);
			$transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
			imagefill($dst, 0, 0, $transparent);
			imagealphablending($dst, true);
		}

		if ($crop) {
			$side = min($sw, $sh);
			$sx   = (int) floor(($sw - $side) / 2);
			$sy   = (int) floor(($sh - $side) / 2);
			imagecopyresampled($dst, $source, 0, 0, $sx, $sy, $width, $height, $side, $side);
		} else {
			imagecopyresampled($dst, $source, 0, 0, 0, 0, $width, $height, $sw, $sh);
		}

		$ok = false;
		if ($format === 'jpeg') {
			$ok = imagejpeg($dst, $dest, (int) ($settings['jpeg_quality'] ?? 85));
		} elseif ($format === 'webp') {
			$ok = imagewebp($dst, $dest, (int) ($settings['webp_quality'] ?? 82));
		} elseif ($format === 'avif' && function_exists('imageavif')) {
			$ok = imageavif($dst, $dest, (int) ($settings['avif_quality'] ?? 65));
		}

		imagedestroy($dst);

		if (! $ok || ! file_exists($dest)) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: format name */
					__('Échec écriture variante %s.', 'lumen-wp'),
					$format
				)
			);
		}
	}

	/**
	 * Prefer modern formats when replacing JPEG/PNG/JPG originals.
	 * Priority: WebP → AVIF → JPEG.
	 *
	 * @param array<string, mixed> $files
	 * @param list<string>         $formats
	 * @return array{path: string, mime: string}|null
	 */
	private function pick_replacement_source(array $files, array $formats): ?array
	{
		$priority = ['webp', 'avif', 'jpeg'];
		$mimes    = [
			'webp' => 'image/webp',
			'avif' => 'image/avif',
			'jpeg' => 'image/jpeg',
		];

		foreach ($priority as $format) {
			if (! in_array($format, $formats, true)) {
				continue;
			}
			if (empty($files[$format]['path']) || ! is_readable((string) $files[$format]['path'])) {
				continue;
			}

			return [
				'path' => (string) $files[$format]['path'],
				'mime' => $mimes[$format],
			];
		}

		return null;
	}

	private function replace_original(
		int $attachment_id,
		string $original,
		string $optimized,
		string $new_mime = 'image/webp'
	): bool {
		if (! is_readable($optimized)) {
			return false;
		}

		$current_mime = (string) get_post_mime_type($attachment_id);
		Original_Backup::ensure($attachment_id, $original, $current_mime !== '' ? $current_mime : 'image/jpeg');

		$ext_map = [
			'image/webp' => 'webp',
			'image/avif' => 'avif',
			'image/jpeg' => 'jpg',
		];
		$ext = $ext_map[$new_mime] ?? 'webp';

		$backup = $original . '.lumen-bak';
		if (! @copy($original, $backup)) {
			throw new \RuntimeException(__('Impossible de créer le backup avant remplacement.', 'lumen-wp'));
		}

		$new_path = preg_replace('/\.[^.]+$/', '.' . $ext, $original);
		if (! is_string($new_path) || $new_path === '') {
			$new_path = $original . '.' . $ext;
		}

		// Same path (e.g. already .webp): overwrite in place via temp copy.
		if ($new_path === $original) {
			$tmp = $original . '.lumen-tmp.' . $ext;
			if (! @copy($optimized, $tmp)) {
				@unlink($backup);
				throw new \RuntimeException(__('Échec du remplacement de l’original.', 'lumen-wp'));
			}
			if (! @rename($tmp, $original)) {
				@copy($backup, $original);
				@unlink($tmp);
				@unlink($backup);
				throw new \RuntimeException(__('Échec du remplacement de l’original.', 'lumen-wp'));
			}
			@unlink($backup);
			wp_update_post(
				[
					'ID'             => $attachment_id,
					'post_mime_type' => $new_mime,
				]
			);

			return true;
		}

		if (! @copy($optimized, $new_path)) {
			@copy($backup, $original);
			@unlink($backup);
			throw new \RuntimeException(__('Échec du remplacement de l’original.', 'lumen-wp'));
		}

		if (file_exists($original)) {
			@unlink($original);
		}
		@unlink($backup);

		update_attached_file($attachment_id, $new_path);
		wp_update_post(
			[
				'ID'             => $attachment_id,
				'post_mime_type' => $new_mime,
			]
		);

		// Keep WP metadata in sync with the new extension/MIME.
		$meta = wp_get_attachment_metadata($attachment_id);
		if (is_array($meta)) {
			$uploads = wp_upload_dir();
			$basedir = trailingslashit(str_replace('\\', '/', $uploads['basedir']));
			$norm    = str_replace('\\', '/', $new_path);
			if (strpos($norm, $basedir) === 0) {
				$meta['file'] = ltrim(substr($norm, strlen($basedir)), '/');
			} else {
				$meta['file'] = basename($new_path);
			}
			if (function_exists('wp_getimagesize')) {
				$info = @wp_getimagesize($new_path);
			} else {
				$info = @getimagesize($new_path);
			}
			if (is_array($info)) {
				$meta['width']  = (int) $info[0];
				$meta['height'] = (int) $info[1];
				if (! empty($info['mime'])) {
					$meta['sizes'] = $meta['sizes'] ?? [];
				}
			}
			wp_update_attachment_metadata($attachment_id, $meta);
		}

		clean_post_cache($attachment_id);

		// Rewrite hardcoded URLs in content / Elementor (jpg → webp, etc.).
		Content_Url_Rewriter::after_attachment_path_change($attachment_id, $original, $new_path);

		return true;
	}

	private function file_to_url(string $file): string
	{
		$uploads = wp_upload_dir();
		$basedir = trailingslashit(str_replace('\\', '/', $uploads['basedir']));
		$baseurl = untrailingslashit($uploads['baseurl']);
		$norm    = str_replace('\\', '/', $file);

		if (strpos($norm, $basedir) === 0) {
			$rel = ltrim(substr($norm, strlen($basedir)), '/');

			return $baseurl . '/' . $rel;
		}

		return $baseurl . '/' . basename($file);
	}
}
