<?php

declare(strict_types=1);

namespace LumenWp;

/**
 * Compression PDF (Ghostscript) avec repli Imagick, API externe ou SEO seul.
 */
final class Pdf_Optimizer
{
	public const META_PREVIEW = '_lumen_pdf_preview';

	/**
	 * @return array{ok: bool, compressed: bool, message: string}
	 */
	public function process(int $attachment_id): array
	{
		$file = get_attached_file($attachment_id);
		if (! is_string($file) || ! is_readable($file)) {
			return $this->result(false, false, __('Fichier PDF introuvable.', 'lumen-wp'));
		}

		@set_time_limit(180);

		if (Server_Caps::ghostscript_binary() !== null) {
			return $this->process_with_ghostscript($attachment_id, $file);
		}

		return $this->process_fallback($attachment_id, $file);
	}

	public static function preview_path(int $attachment_id): ?string
	{
		$stored = (string) get_post_meta($attachment_id, self::META_PREVIEW, true);
		if ($stored === '') {
			return null;
		}
		$upload = wp_upload_dir();
		$path   = trailingslashit((string) $upload['basedir']) . ltrim($stored, '/');

		return is_readable($path) ? $path : null;
	}

	/**
	 * @return array{ok: bool, compressed: bool, message: string}
	 */
	private function process_with_ghostscript(int $attachment_id, string $file): array
	{
		$preview = $this->preview_gs($file, $attachment_id);
		if ($preview['relative'] !== '') {
			update_post_meta($attachment_id, self::META_PREVIEW, $preview['relative']);
		}

		if (empty(Plugin::instance()->settings()['replace_original'])) {
			return $this->result(true, false, __('Aperçu PDF généré. La compression remplace le fichier seulement si « Remplacer l’original » est coché.', 'lumen-wp'));
		}

		$compressed = $this->compress_gs($attachment_id, $file);
		if ($compressed) {
			return $this->result(true, true, __('PDF optimisé et aperçu généré.', 'lumen-wp'));
		}

		return $this->result(true, false, __('Aperçu PDF généré (compression sans gain).', 'lumen-wp'));
	}

	/**
	 * @return array{ok: bool, compressed: bool, message: string}
	 */
	private function process_fallback(int $attachment_id, string $file): array
	{
		$mode = (string) (Plugin::instance()->settings()['pdf_fallback'] ?? 'imagick');
		if (! in_array($mode, ['imagick', 'external_api', 'seo_only'], true)) {
			$mode = 'imagick';
		}

		$compressed = false;
		$message    = '';

		if ($mode === 'external_api' && (new Pdf_External_Client())->is_configured()) {
			$info = pathinfo($file);
			$out  = trailingslashit((string) ($info['dirname'] ?? dirname($file))) . (string) ($info['filename'] ?? 'doc') . '-lumen-api.pdf';
			$result = (new Pdf_External_Client())->compress($file, $out);
			if (! empty($result['success']) && is_readable($out)) {
				$compressed = $this->replace_pdf($attachment_id, $file, $out);
				$message    = $compressed
					? __('PDF compressé via API externe.', 'lumen-wp')
					: __('API PDF : fichier non remplacé (pas plus léger, ou remplacement désactivé).', 'lumen-wp');
				if (! $compressed) {
					@unlink($out);
				}
			} else {
				@unlink($out);
				$message = (string) ($result['message'] ?? __('Échec API PDF externe.', 'lumen-wp'));
			}
		}

		$preview = $this->preview_imagick($file, $attachment_id);
		if ($preview['relative'] !== '') {
			update_post_meta($attachment_id, self::META_PREVIEW, $preview['relative']);
		}

		if ($mode === 'seo_only') {
			return $this->result(true, false, __('Mode dégradé : SEO seul, sans compression PDF.', 'lumen-wp'));
		}

		if ($message === '') {
			$message = $preview['relative'] !== ''
				? __('Mode dégradé : aperçu Imagick, sans compression Ghostscript.', 'lumen-wp')
				: __('Ghostscript absent — aucun aperçu PDF généré.', 'lumen-wp');
		}

		return $this->result(true, $compressed, $message);
	}

	private function compress_gs(int $attachment_id, string $file): bool
	{
		$gs = Server_Caps::ghostscript_binary();
		if ($gs === null || ! function_exists('exec')) {
			return false;
		}

		$info = pathinfo($file);
		$out  = trailingslashit((string) ($info['dirname'] ?? dirname($file))) . (string) ($info['filename'] ?? 'doc') . '-lumen-opt.pdf';
		$preset = (string) (Plugin::instance()->settings()['pdf_preset'] ?? 'ebook');
		$setting = $this->preset_to_gs($preset);

		$cmd = sprintf(
			'%s -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=%s -dNOPAUSE -dQUIET -dBATCH -sOutputFile=%s %s 2>&1',
			escapeshellarg($gs),
			escapeshellarg($setting),
			escapeshellarg($out),
			escapeshellarg($file)
		);
		$this->run($cmd);

		if (! is_readable($out)) {
			return false;
		}

		$ok = $this->replace_pdf($attachment_id, $file, $out);
		if (! $ok) {
			@unlink($out);
		}

		return $ok;
	}

	private function replace_pdf(int $attachment_id, string $original, string $produced): bool
	{
		$settings = Plugin::instance()->settings();
		if (empty($settings['replace_original'])) {
			return false;
		}

		$new_size = (int) (@filesize($produced) ?: 0);
		$old_size = (int) (@filesize($original) ?: 0);
		if ($new_size <= 0 || $new_size >= $old_size) {
			return false;
		}

		$head = file_get_contents($produced, false, null, 0, 5);
		if (! is_string($head) || strncmp($head, '%PDF', 4) !== 0) {
			return false;
		}

		try {
			Original_Backup::ensure($attachment_id, $original, 'application/pdf');
		} catch (\Throwable $e) {
			return false;
		}

		$swap = $original . '.lumen-swap.pdf';
		$moved = @rename($produced, $swap);
		if (! $moved) {
			$moved = @copy($produced, $swap);
			@unlink($produced);
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

		return $moved && is_readable($original);
	}

	/**
	 * @return array{path: string, relative: string}
	 */
	private function preview_gs(string $pdf_path, int $attachment_id): array
	{
		$empty = ['path' => '', 'relative' => ''];
		$gs    = Server_Caps::ghostscript_binary();
		if ($gs === null || ! function_exists('exec')) {
			return $empty;
		}

		$target = $this->preview_target($attachment_id);
		$cmd    = sprintf(
			'%s -dNOPAUSE -dBATCH -sDEVICE=jpeg -dFirstPage=1 -dLastPage=1 -r150 -dJPEGQ=85 -sOutputFile=%s %s 2>&1',
			escapeshellarg($gs),
			escapeshellarg($target['path']),
			escapeshellarg($pdf_path)
		);
		$this->run($cmd);

		return is_readable($target['path']) ? $target : $empty;
	}

	/**
	 * @return array{path: string, relative: string}
	 */
	private function preview_imagick(string $pdf_path, int $attachment_id): array
	{
		$empty = ['path' => '', 'relative' => ''];
		if (! class_exists(\Imagick::class)) {
			return $empty;
		}

		try {
			$target  = $this->preview_target($attachment_id);
			$imagick = new \Imagick();
			$imagick->setResolution(150, 150);
			$imagick->readImage($pdf_path . '[0]');
			$imagick->setImageFormat('jpeg');
			$imagick->setImageCompressionQuality(85);
			$imagick->writeImage($target['path']);
			$imagick->clear();
			$imagick->destroy();

			return is_readable($target['path']) ? $target : $empty;
		} catch (\Throwable $e) {
			return $empty;
		}
	}

	/**
	 * @return array{path: string, relative: string}
	 */
	private function preview_target(int $attachment_id): array
	{
		$upload = wp_upload_dir();
		$subdir = trailingslashit((string) ($upload['subdir'] ?? gmdate('Y/m')));
		$dir    = trailingslashit((string) $upload['basedir']) . $subdir;
		wp_mkdir_p($dir);
		$basename = 'lumen-pdf-' . $attachment_id . '-' . wp_generate_password(6, false) . '.jpg';

		return [
			'path'     => $dir . $basename,
			'relative' => $subdir . $basename,
		];
	}

	private function preset_to_gs(string $preset): string
	{
		switch ($preset) {
			case 'screen':
				return '/screen';
			case 'print':
				return '/printer';
			case 'prepress':
				return '/prepress';
			default:
				return '/ebook';
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
