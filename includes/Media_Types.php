<?php

declare(strict_types=1);

namespace LumenWp;

/**
 * Classification des pièces jointes Lumen (image raster, SVG, PDF, vidéo).
 */
final class Media_Types
{
	public const KIND_IMAGE = 'image';
	public const KIND_SVG   = 'svg';
	public const KIND_PDF   = 'pdf';
	public const KIND_VIDEO = 'video';
	public const KIND_OTHER = 'other';

	/**
	 * @return list<string>
	 */
	public static function all_types(): array
	{
		return [self::KIND_IMAGE, self::KIND_PDF, self::KIND_SVG, self::KIND_VIDEO];
	}

	/**
	 * @param mixed $types
	 * @return list<string>
	 */
	public static function normalize_types($types): array
	{
		if (! is_array($types)) {
			return self::all_types();
		}

		$allowed = self::all_types();
		$out     = [];
		foreach ($types as $type) {
			$type = strtolower(trim((string) $type));
			if (in_array($type, $allowed, true) && ! in_array($type, $out, true)) {
				$out[] = $type;
			}
		}

		return $out;
	}

	public static function kind(int $attachment_id): string
	{
		return self::kind_from_mime((string) get_post_mime_type($attachment_id));
	}

	public static function kind_from_mime(string $mime): string
	{
		$mime = strtolower(trim($mime));
		if ($mime === '') {
			return self::KIND_OTHER;
		}

		if ($mime === 'image/svg+xml' || $mime === 'image/svg') {
			return self::KIND_SVG;
		}

		if (strpos($mime, 'image/') === 0) {
			return self::KIND_IMAGE;
		}

		if ($mime === 'application/pdf') {
			return self::KIND_PDF;
		}

		if (strpos($mime, 'video/') === 0) {
			return self::KIND_VIDEO;
		}

		return self::KIND_OTHER;
	}

	public static function is_supported(int $attachment_id): bool
	{
		return self::kind($attachment_id) !== self::KIND_OTHER;
	}

	public static function supports_optimize(string $kind): bool
	{
		return $kind === self::KIND_IMAGE;
	}

	/** IA Vision : images raster, PDF et vidéos (pas SVG). */
	public static function supports_ai(string $kind): bool
	{
		return in_array($kind, [self::KIND_IMAGE, self::KIND_PDF, self::KIND_VIDEO], true);
	}

	public static function label(string $kind): string
	{
		$map = [
			self::KIND_IMAGE => __('Images', 'lumen-wp'),
			self::KIND_PDF   => __('PDF', 'lumen-wp'),
			self::KIND_SVG   => __('SVG', 'lumen-wp'),
			self::KIND_VIDEO => __('Vidéos', 'lumen-wp'),
		];

		return $map[$kind] ?? $kind;
	}

	/**
	 * Clause SQL (sans préfixe de table) pour filtrer les MIME des types choisis.
	 * Ex. : "(p.post_mime_type LIKE 'image/%' AND …) OR p.post_mime_type = 'application/pdf'"
	 *
	 * @param list<string> $types
	 */
	public static function mime_where_sql(array $types, string $alias = 'p'): string
	{
		$types = self::normalize_types($types);
		if ($types === []) {
			return '0=1';
		}

		$parts = [];
		foreach ($types as $type) {
			switch ($type) {
				case self::KIND_IMAGE:
					$parts[] = "({$alias}.post_mime_type LIKE 'image/%' AND {$alias}.post_mime_type NOT IN ('image/svg+xml','image/svg'))";
					break;
				case self::KIND_SVG:
					$parts[] = "{$alias}.post_mime_type IN ('image/svg+xml','image/svg')";
					break;
				case self::KIND_PDF:
					$parts[] = "{$alias}.post_mime_type = 'application/pdf'";
					break;
				case self::KIND_VIDEO:
					$parts[] = "{$alias}.post_mime_type LIKE 'video/%'";
					break;
			}
		}

		return $parts === [] ? '0=1' : '(' . implode(' OR ', $parts) . ')';
	}
}
