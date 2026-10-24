<?php

declare(strict_types=1);

namespace LumenWp;

/**
 * Compression vidéo FFmpeg (MP4 / WebM) et miniature poster.
 */
final class Video_Optimizer
{
	public const META_POSTER_PATH = '_lumen_video_poster';
	public const META_POSTER_URL  = '_lumen_video_poster_url';
	public const META_WEBM_URL    = '_lumen_video_webm_url';

	/**
	 * @return array{ok: bool, compressed: bool, message: string}
	 */
	public function process(int $attachment_id): array
	{
		$file = get_attached_file($attachment_id);
		if (! is_string($file) || ! is_readable($file)) {
			return $this->result(false, false, __('Fichier vidéo introuvable.', 'lumen-wp'));
		}

		$ffmpeg = Server_Caps::ffmpeg_binary();
		if ($ffmpeg === null || ! function_exists('exec')) {
			return $this->result(true, false, __('FFmpeg indisponible — vidéo laissée telle quelle.', 'lumen-wp'));
		}

		@set_time_limit(300);

		$poster = $this->extract_poster($ffmpeg, $file, $attachment_id);
		$compress = $this->maybe_compress($ffmpeg, $file, $attachment_id);

		if ($poster['url'] !== '') {
			update_post_meta($attachment_id, self::META_POSTER_PATH, $poster['relative']);
			update_post_meta($attachment_id, self::META_POSTER_URL, $poster['url']);
			$this->attach_poster_metadata($attachment_id, $poster);
		}

		if (! empty($compress['webm_url'])) {
			update_post_meta($attachment_id, self::META_WEBM_URL, $compress['webm_url']);
		}

		if (! empty($compress['compressed'])) {
			$this->regenerate_metadata($attachment_id, (string) $compress['path']);
			if ($poster['path'] !== '') {
				$this->attach_poster_metadata($attachment_id, $poster);
			}
		}

		return $this->result(true, ! empty($compress['compressed']), (string) $compress['message']);
	}

	public static function poster_path(int $attachment_id): ?string
	{
		$stored = (string) get_post_meta($attachment_id, self::META_POSTER_PATH, true);
		if ($stored === '') {
			return null;
		}

		$upload = wp_upload_dir();
		$path   = trailingslashit((string) $upload['basedir']) . ltrim($stored, '/');

		return is_readable($path) ? $path : null;
	}

	/**
	 * @return array{path: string, url: string, relative: string, width: int, height: int}
	 */
	private function extract_poster(string $ffmpeg, string $video_file, int $attachment_id): array
	{
		$empty = ['path' => '', 'url' => '', 'relative' => '', 'width' => 0, 'height' => 0];
		$info  = pathinfo($video_file);
		$poster = trailingslashit((string) ($info['dirname'] ?? '')) . (string) ($info['filename'] ?? 'video') . '-lumen-poster.jpg';

		$cmd = sprintf(
			'%s -y -ss 00:00:01 -i %s -frames:v 1 -q:v 2 %s 2>&1',
			escapeshellarg($ffmpeg),
			escapeshellarg($video_file),
			escapeshellarg($poster)
		);
		$this->run($cmd);

		if (! is_readable($poster)) {
			$cmd = sprintf(
				'%s -y -i %s -frames:v 1 -q:v 2 %s 2>&1',
				escapeshellarg($ffmpeg),
				escapeshellarg($video_file),
				escapeshellarg($poster)
			);
			$this->run($cmd);
		}

		if (! is_readable($poster)) {
			return $empty;
		}

		$size   = function_exists('wp_getimagesize') ? wp_getimagesize($poster) : @getimagesize($poster);
		$upload = wp_upload_dir();
		$relative = ltrim(str_replace(trailingslashit((string) $upload['basedir']), '', $poster), '/');

		return [
			'path'     => $poster,
			'url'      => trailingslashit((string) $upload['baseurl']) . $relative,
			'relative' => $relative,
			'width'    => (int) (is_array($size) ? ($size[0] ?? 0) : 0),
			'height'   => (int) (is_array($size) ? ($size[1] ?? 0) : 0),
		];
	}

	/**
	 * @return array{compressed: bool, path: string, webm_url: string, message: string}
	 */
	private function maybe_compress(string $ffmpeg, string $file, int $attachment_id): array
	{
		$settings = Plugin::instance()->settings();
		$format   = (string) ($settings['video_format'] ?? 'mp4');
		if (! in_array($format, ['mp4', 'webm', 'mp4_webm'], true)) {
			$format = 'mp4';
		}
		$crf       = max(18, min(36, (int) ($settings['video_crf'] ?? 28)));
		$max_width = max(640, min(3840, (int) ($settings['video_max_width'] ?? 1920)));
		$scale     = sprintf('scale=w=min(%d\\,iw):h=-2', $max_width);
		$info      = pathinfo($file);
		$dir       = trailingslashit((string) ($info['dirname'] ?? dirname($file)));
		$base      = (string) ($info['filename'] ?? 'video');
		$mp4_tmp   = $dir . $base . '-lumen-tmp.mp4';
		$webm_tmp  = $dir . $base . '-lumen-tmp.webm';

		$encode_mp4  = in_array($format, ['mp4', 'mp4_webm'], true);
		$encode_webm = in_array($format, ['webm', 'mp4_webm'], true);
		$compressed  = false;
		$webm_url    = '';
		$path        = $file;
		$failed      = false;

		if ($encode_mp4) {
			$cmd = sprintf(
				'%s -y -i %s -c:v libx264 -crf %d -preset medium -vf %s -movflags +faststart -c:a aac -b:a 128k %s 2>&1',
				escapeshellarg($ffmpeg),
				escapeshellarg($file),
				$crf,
				escapeshellarg($scale),
				escapeshellarg($mp4_tmp)
			);
			if (! $this->run($cmd) || ! is_readable($mp4_tmp)) {
				$failed = true;
				@unlink($mp4_tmp);
			}
		}

		if ($encode_webm) {
			$cmd = sprintf(
				'%s -y -i %s -c:v libvpx-vp9 -crf %d -b:v 0 -vf %s -c:a libopus -b:a 128k %s 2>&1',
				escapeshellarg($ffmpeg),
				escapeshellarg($file),
				min(36, $crf + 4),
				escapeshellarg($scale),
				escapeshellarg($webm_tmp)
			);
			if (! $this->run($cmd) || ! is_readable($webm_tmp)) {
				$failed = true;
				@unlink($webm_tmp);
			}
		}

		if ($encode_mp4 && is_readable($mp4_tmp)) {
			$dest = $dir . $base . '.mp4';
			if ($this->replace_attachment_file($attachment_id, $file, $mp4_tmp, $dest, 'video/mp4')) {
				$compressed = true;
				$path       = $dest;
				$file       = $dest;
			}
		}

		if ($encode_webm && is_readable($webm_tmp)) {
			$dest = $dir . $base . '.webm';
			if ($format === 'webm') {
				if ($this->replace_attachment_file($attachment_id, $file, $webm_tmp, $dest, 'video/webm')) {
					$compressed = true;
					$path       = $dest;
				}
			} else {
				if ($dest !== $webm_tmp && is_file($dest)) {
					@unlink($dest);
				}
				if (@rename($webm_tmp, $dest) || (@copy($webm_tmp, $dest) && @unlink($webm_tmp))) {
					$upload   = wp_upload_dir();
					$relative = ltrim(str_replace(trailingslashit((string) $upload['basedir']), '', $dest), '/');
					$webm_url = trailingslashit((string) $upload['baseurl']) . $relative;
					$compressed = true;
				}
			}
		}

		if ($compressed) {
			$message = __('Vidéo optimisée et miniature générée.', 'lumen-wp');
		} elseif ($failed) {
			$message = __('Miniature générée — échec de l’encodage FFmpeg.', 'lumen-wp');
		} elseif (empty($settings['replace_original'])) {
			$message = __('Miniature générée. La compression remplace le fichier seulement si « Remplacer l’original » est coché.', 'lumen-wp');
		} else {
			$message = __('Miniature générée (compression sans gain, fichier d’origine conservé).', 'lumen-wp');
		}

		return [
			'compressed' => $compressed,
			'path'       => $path,
			'webm_url'   => $webm_url,
			'message'    => $message,
		];
	}

	private function replace_attachment_file(int $attachment_id, string $original, string $tmp, string $dest, string $mime): bool
	{
		$settings = Plugin::instance()->settings();
		if (empty($settings['replace_original'])) {
			@unlink($tmp);

			return false;
		}

		$orig_size   = (int) (@filesize($original) ?: 0);
		$new_size    = (int) (@filesize($tmp) ?: 0);
		$ext_changed = strtolower((string) pathinfo($original, PATHINFO_EXTENSION)) !== strtolower((string) pathinfo($dest, PATHINFO_EXTENSION));
		if ($new_size <= 0 || ($new_size >= $orig_size && ! $ext_changed)) {
			@unlink($tmp);

			return false;
		}

		$mime_now = (string) get_post_mime_type($attachment_id);
		try {
			Original_Backup::ensure($attachment_id, $original, $mime_now !== '' ? $mime_now : $mime);
		} catch (\Throwable $e) {
			@unlink($tmp);

			return false;
		}

		if ($dest === $original) {
			$swap = $original . '.lumen-swap';
			$moved = @rename($tmp, $swap);
			if (! $moved) {
				$moved = @copy($tmp, $swap);
				@unlink($tmp);
			}
			if (! $moved || ! is_readable($swap)) {
				return false;
			}
			@unlink($original);
			$moved = @rename($swap, $original);
			if (! $moved) {
				$moved = @copy($swap, $original);
				@unlink($swap);
			}
			$dest = $original;
		} else {
			if (is_file($dest)) {
				@unlink($dest);
			}
			$moved = @rename($tmp, $dest);
			if (! $moved) {
				$moved = @copy($tmp, $dest);
				@unlink($tmp);
			}
			if (! $moved || ! is_readable($dest)) {
				return false;
			}
			if (is_file($original)) {
				@unlink($original);
			}
		}

		update_attached_file($attachment_id, $dest);
		wp_update_post([
			'ID'             => $attachment_id,
			'post_mime_type' => $mime,
		]);

		if (! empty($settings['rewrite_content_urls']) && $dest !== $original) {
			Content_Url_Rewriter::after_attachment_path_change($attachment_id, $original, $dest, true);
		}

		return true;
	}

	/**
	 * @param array{path: string, url: string, relative: string, width: int, height: int} $poster
	 */
	private function attach_poster_metadata(int $attachment_id, array $poster): void
	{
		$metadata = wp_get_attachment_metadata($attachment_id);
		if (! is_array($metadata)) {
			$metadata = [];
		}
		$metadata['image'] = [
			'file'      => basename($poster['path']),
			'width'     => $poster['width'],
			'height'    => $poster['height'],
			'mime-type' => 'image/jpeg',
			'src'       => $poster['url'],
		];
		wp_update_attachment_metadata($attachment_id, $metadata);
	}

	private function regenerate_metadata(int $attachment_id, string $path): void
	{
		if (! is_readable($path)) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata($attachment_id, $path);
		if (is_array($metadata)) {
			wp_update_attachment_metadata($attachment_id, $metadata);
		}
	}

	private function run(string $command): bool
	{
		if (! function_exists('exec')) {
			return false;
		}
		$output = [];
		$code   = 1;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec($command, $output, $code);

		return $code === 0;
	}

	/**
	 * @return array{ok: bool, compressed: bool, message: string}
	 */
	private function result(bool $ok, bool $compressed, string $message): array
	{
		return [
			'ok'         => $ok,
			'compressed' => $compressed,
			'message'    => $message,
		];
	}
}
