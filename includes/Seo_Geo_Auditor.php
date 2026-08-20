<?php

declare(strict_types=1);

namespace LumenWp;

/**
 * On-page SEO / GEO audit (Nimbus-inspired, Lumen media metas).
 */
final class Seo_Geo_Auditor
{
	public const OPTION_LAST = 'lumen_wp_last_audit';
	private const AFFECTED_LIMIT = 50;

	/**
	 * @return array{score: int, items: list<array<string, mixed>>, summary: array<string, int>, generated_at: string}
	 */
	public function run(): array
	{
		$items = array_merge(
			$this->audit_media_alt(),
			$this->audit_unoptimized_images(),
			$this->audit_unoptimized_videos(),
			$this->audit_post_seo_meta(),
			$this->audit_post_excerpts(),
			$this->audit_faq_schema(),
			$this->audit_site_basics(),
			$this->audit_geo_signals()
		);

		usort(
			$items,
			static function ($a, $b): int {
				return (int) ($b['priority'] ?? 0) <=> (int) ($a['priority'] ?? 0);
			}
		);

		return [
			'score'        => $this->calculate_score($items),
			'items'        => $items,
			'summary'      => [
				'critical' => count(array_filter($items, static fn ($i) => ($i['severity'] ?? '') === 'critical')),
				'warning'  => count(array_filter($items, static fn ($i) => ($i['severity'] ?? '') === 'warning')),
				'info'     => count(array_filter($items, static fn ($i) => ($i['severity'] ?? '') === 'info')),
			],
			'generated_at' => current_time('mysql'),
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function audit_media_alt(): array
	{
		global $wpdb;
		$limit = self::AFFECTED_LIMIT;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_mime_type, p.post_date
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_image_alt'
				WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s
				AND (pm.meta_value IS NULL OR pm.meta_value = '')
				ORDER BY p.post_date DESC
				LIMIT %d",
				$wpdb->esc_like('image/') . '%',
				$limit
			),
			ARRAY_A
		);

		if (empty($rows)) {
			return [];
		}

		$affected = array_map([$this, 'map_attachment_entity'], $rows);

		return [
			[
				'id'           => 'missing_alt',
				'severity'     => 'critical',
				'priority'     => 90,
				'fixable'      => true,
				'title'        => __('Images sans texte alternatif', 'lumen-wp'),
				'description'  => sprintf(
					/* translators: %d: count */
					__('%d image(s) sans attribut alt — accessibilité et SEO.', 'lumen-wp'),
					count($affected)
				),
				'action'       => __('Générer les alt via le pipeline Lumen (IA si configurée).', 'lumen-wp'),
				'fix_preview'  => __('Chaque image sera traitée (optimisation + SEO). Avec une clé IA, le texte alternatif sera généré ; sinon règles locales.', 'lumen-wp'),
				'affected'     => $affected,
				'affected_ids' => array_column($affected, 'id'),
			],
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function audit_unoptimized_images(): array
	{
		global $wpdb;
		$status   = Plugin::META_STATUS;
		$variants = Plugin::META_VARIANTS;
		$limit    = self::AFFECTED_LIMIT;
		$replace  = ! empty(Plugin::instance()->settings()['replace_original']);

		if ($replace) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, p.post_mime_type, p.post_date
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} s ON p.ID = s.post_id AND s.meta_key = %s
					WHERE p.post_type = 'attachment'
					AND p.post_mime_type IN ('image/jpeg','image/png','image/gif')
					AND NOT (
						s.meta_id IS NOT NULL AND s.meta_value IN ('ok','awaiting_validation')
					)
					ORDER BY p.post_date DESC
					LIMIT %d",
					$status,
					$limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, p.post_mime_type, p.post_date
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} v ON p.ID = v.post_id AND v.meta_key = %s
					WHERE p.post_type = 'attachment'
					AND p.post_mime_type IN ('image/jpeg','image/png','image/gif')
					AND v.meta_id IS NULL
					ORDER BY p.post_date DESC
					LIMIT %d",
					$variants,
					$limit
				),
				ARRAY_A
			);
		}

		if (empty($rows)) {
			return [];
		}

		$formats  = Plugin::instance()->settings()['formats'] ?? ['webp'];
		$target   = strtoupper(is_array($formats) && $formats !== [] ? (string) $formats[0] : 'WEBP');
		$affected = array_map(
			function (array $row) use ($target): array {
				$entity                   = $this->map_attachment_entity($row);
				$entity['current_format'] = $this->mime_to_label((string) ($row['post_mime_type'] ?? ''));
				$entity['target_format']  = $target;
				$entity['issue']          = sprintf('%s → %s', $entity['current_format'], $target);

				return $entity;
			},
			$rows
		);

		return [
			[
				'id'           => 'unoptimized_images',
				'severity'     => 'warning',
				'priority'     => 70,
				'fixable'      => true,
				'title'        => __('Images non optimisées', 'lumen-wp'),
				'description'  => sprintf(
					/* translators: 1: count 2: target format */
					__('%1$d image(s) JPEG/PNG/GIF à convertir / compresser (%2$s).', 'lumen-wp'),
					count($affected),
					$target
				),
				'action'       => __('Lancer le traitement Lumen.', 'lumen-wp'),
				'fix_preview'  => sprintf(
					/* translators: %s: format */
					__('Compression et conversion vers %s via la file Traitement.', 'lumen-wp'),
					$target
				),
				'affected'     => $affected,
				'affected_ids' => array_column($affected, 'id'),
			],
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function audit_unoptimized_videos(): array
	{
		global $wpdb;
		$status = Plugin::META_STATUS;
		$limit  = self::AFFECTED_LIMIT;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_mime_type, p.post_date
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} s ON p.ID = s.post_id AND s.meta_key = %s
				WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s
				AND NOT (
					s.meta_id IS NOT NULL AND s.meta_value IN ('ok','awaiting_validation')
				)
				ORDER BY p.post_date DESC
				LIMIT %d",
				$status,
				$wpdb->esc_like('video/') . '%',
				$limit
			),
			ARRAY_A
		);

		if (empty($rows)) {
			return [];
		}

		$affected = array_map(
			function (array $row): array {
				$entity                   = $this->map_attachment_entity($row);
				$entity['current_format'] = $this->mime_to_label((string) ($row['post_mime_type'] ?? ''));
				$entity['issue']          = sprintf(
					/* translators: %s: format */
					__('Format %s non traité', 'lumen-wp'),
					$entity['current_format']
				);

				return $entity;
			},
			$rows
		);

		return [
			[
				'id'           => 'unoptimized_videos',
				'severity'     => 'warning',
				'priority'     => 65,
				'fixable'      => true,
				'title'        => __('Vidéos non traitées', 'lumen-wp'),
				'description'  => sprintf(
					/* translators: %d: count */
					__('%d vidéo(s) sans traitement Lumen (SEO / métadonnées).', 'lumen-wp'),
					count($affected)
				),
				'action'       => __('Traiter via Lumen (IA optionnelle).', 'lumen-wp'),
				'fix_preview'  => __('Pipeline Lumen sur chaque vidéo listée (métadonnées, schema si applicable).', 'lumen-wp'),
				'affected'     => $affected,
				'affected_ids' => array_column($affected, 'id'),
			],
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function audit_post_seo_meta(): array
	{
		$bridge = new Seo_Plugin_Bridge();
		if ($bridge->get_active_plugin() === null) {
			return [];
		}

		$posts    = get_posts(
			[
				'post_type'      => ['post', 'page'],
				'post_status'    => 'publish',
				'posts_per_page' => self::AFFECTED_LIMIT,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			]
		);
		$affected = [];
		$label    = $bridge->get_active_plugin_label();

		foreach ($posts as $post) {
			$seo = $bridge->read_post_seo((int) $post->ID);
			if (trim($seo['title']) !== '' && trim($seo['description']) !== '') {
				continue;
			}
			$entity  = $this->map_post_entity($post);
			$issues  = [];
			if (trim($seo['title']) === '') {
				$issues[] = __('Meta title manquante', 'lumen-wp');
			}
			if (trim($seo['description']) === '') {
				$issues[] = __('Meta description manquante', 'lumen-wp');
			}
			$entity['issue']     = implode(' · ', $issues);
			$entity['find_hint'] = sprintf(
				/* translators: 1: SEO plugin 2: title */
				__('%1$s → modifier « %2$s »', 'lumen-wp'),
				$label,
				$post->post_title
			);
			$affected[] = $entity;
		}

		if ($affected === []) {
			return [];
		}

		return [
			[
				'id'           => 'missing_seo_meta',
				'severity'     => 'warning',
				'priority'     => 62,
				'fixable'      => true,
				'title'        => sprintf(
					/* translators: %s: plugin */
					__('Meta title / description %s manquantes', 'lumen-wp'),
					$label
				),
				'description'  => sprintf(
					/* translators: %d: count */
					__('%d contenu(s) sans meta title ou description SEO.', 'lumen-wp'),
					count($affected)
				),
				'action'       => __('Générer depuis le contenu et synchroniser le plugin SEO.', 'lumen-wp'),
				'fix_preview'  => __('Title (~60 car.) et description (~160 car.) dérivés du contenu, écrits dans le plugin SEO actif.', 'lumen-wp'),
				'affected'     => $affected,
				'affected_ids' => array_column($affected, 'id'),
			],
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function audit_post_excerpts(): array
	{
		$bridge = new Seo_Plugin_Bridge();
		if ($bridge->get_active_plugin() !== null) {
			return [];
		}

		$posts    = get_posts(
			[
				'post_type'      => ['post', 'page'],
				'post_status'    => 'publish',
				'posts_per_page' => self::AFFECTED_LIMIT,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			]
		);
		$affected = [];
		foreach ($posts as $post) {
			if (trim((string) $post->post_excerpt) !== '') {
				continue;
			}
			$affected[] = $this->map_post_entity($post);
		}

		if ($affected === []) {
			return [];
		}

		return [
			[
				'id'           => 'missing_excerpt',
				'severity'     => 'warning',
				'priority'     => 60,
				'fixable'      => true,
				'title'        => __('Pages / articles sans extrait', 'lumen-wp'),
				'description'  => sprintf(
					/* translators: %d: count */
					__('%d contenu(s) sans extrait (meta description de base).', 'lumen-wp'),
					count($affected)
				),
				'action'       => __('Générer un extrait depuis le contenu.', 'lumen-wp'),
				'fix_preview'  => __('Extrait ~160 caractères écrit dans le champ Extrait WordPress.', 'lumen-wp'),
				'affected'     => $affected,
				'affected_ids' => array_column($affected, 'id'),
			],
		];
	}

	/**
	 * Detect FAQ-like structure without schema (generation deferred to 1.9).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function audit_faq_schema(): array
	{
		$posts    = get_posts(
			[
				'post_type'      => ['post', 'page'],
				'post_status'    => 'publish',
				'posts_per_page' => self::AFFECTED_LIMIT,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			]
		);
		$affected = [];

		foreach ($posts as $post) {
			if (! $this->looks_like_faq((string) $post->post_content)) {
				continue;
			}
			if ($this->has_faq_schema((string) $post->post_content, (int) $post->ID)) {
				continue;
			}
			$entity          = $this->map_post_entity($post);
			$entity['issue'] = __('FAQ détectable sans schema FAQPage', 'lumen-wp');
			$affected[]      = $entity;
		}

		if ($affected === []) {
			return [];
		}

		return [
			[
				'id'           => 'missing_faq_schema',
				'severity'     => 'info',
				'priority'     => 45,
				'fixable'      => false,
				'title'        => __('Schema FAQPage absent', 'lumen-wp'),
				'description'  => sprintf(
					/* translators: %d: count */
					__('%d contenu(s) avec structure FAQ sans JSON-LD FAQPage (génération prévue en 1.9).', 'lumen-wp'),
					count($affected)
				),
				'action'       => __('Ajouter manuellement le schema ou attendre Lumen 1.9.', 'lumen-wp'),
				'fix_preview'  => '',
				'affected'     => $affected,
				'affected_ids' => array_column($affected, 'id'),
			],
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function audit_site_basics(): array
	{
		if (get_bloginfo('description') !== '') {
			return [];
		}

		return [
			[
				'id'           => 'empty_tagline',
				'severity'     => 'warning',
				'priority'     => 55,
				'fixable'      => false,
				'title'        => __('Slogan du site vide', 'lumen-wp'),
				'description'  => __('Le slogan WordPress (Réglages → Général) est vide.', 'lumen-wp'),
				'action'       => __('Complétez le slogan manuellement.', 'lumen-wp'),
				'fix_preview'  => '',
				'affected'     => [
					[
						'id'        => 0,
						'title'     => get_bloginfo('name'),
						'type'      => 'site',
						'edit_url'  => admin_url('options-general.php'),
						'find_hint' => __('Réglages → Général → Slogan', 'lumen-wp'),
					],
				],
				'affected_ids' => [],
				'link'         => admin_url('options-general.php'),
			],
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function audit_geo_signals(): array
	{
		$llms = new Llms_Txt();
		if ($llms->exists() || ! $llms->is_enabled()) {
			return [];
		}

		return [
			[
				'id'           => 'missing_llms_txt',
				'severity'     => 'info',
				'priority'     => 40,
				'fixable'      => true,
				'title'        => __('Fichier llms.txt absent (GEO)', 'lumen-wp'),
				'description'  => __('Bonus low-cost pour agents IA — impact non garanti.', 'lumen-wp'),
				'action'       => __('Générer llms.txt (URL publique dynamique).', 'lumen-wp'),
				'fix_preview'  => __('Contenu Markdown servi via /llms.txt (rewrite WordPress, sans écriture sur le disque).', 'lumen-wp'),
				'affected'     => [],
				'affected_ids' => [],
			],
		];
	}

	private function looks_like_faq(string $content): bool
	{
		if (preg_match('/<!--\s*wp:yoast\/faq-block/i', $content)) {
			return true;
		}
		if (preg_match('/<!--\s*wp:rank-math\/faq-block/i', $content)) {
			return true;
		}
		// At least 2 heading-like questions.
		if (preg_match_all('/<h[2-4][^>]*>[^<]*\?[^<]*<\/h[2-4]>/iu', $content, $m) && count($m[0]) >= 2) {
			return true;
		}

		return (bool) preg_match('/\bFAQ\b/u', $content);
	}

	private function has_faq_schema(string $content, int $post_id): bool
	{
		if (stripos($content, 'FAQPage') !== false) {
			return true;
		}
		$jsonld = (string) get_post_meta($post_id, Plugin::META_JSONLD, true);
		if (stripos($jsonld, 'FAQPage') !== false) {
			return true;
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function map_attachment_entity(array $row): array
	{
		$id    = (int) ($row['ID'] ?? 0);
		$title = (string) ($row['post_title'] ?? '');
		if ($title === '') {
			$title = basename((string) get_attached_file($id)) ?: '#' . $id;
		}
		$mime = (string) ($row['post_mime_type'] ?? get_post_mime_type($id));

		return [
			'id'        => $id,
			'title'     => $title,
			'type'      => 'attachment',
			'mime'      => $mime,
			'edit_url'  => get_edit_post_link($id, 'raw') ?: admin_url('upload.php?item=' . $id),
			'find_hint' => sprintf(
				/* translators: 1: title 2: format */
				__('Médiathèque → « %1$s » · %2$s', 'lumen-wp'),
				$title,
				$this->mime_to_label($mime)
			),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function map_post_entity(\WP_Post $post): array
	{
		$type_label = $post->post_type === 'page' ? __('Page', 'lumen-wp') : __('Article', 'lumen-wp');

		return [
			'id'         => (int) $post->ID,
			'title'      => $post->post_title !== '' ? $post->post_title : '#' . $post->ID,
			'type'       => $post->post_type,
			'type_label' => $type_label,
			'edit_url'   => get_edit_post_link($post, 'raw') ?: admin_url('edit.php'),
			'find_hint'  => sprintf(
				/* translators: 1: type 2: title */
				__('%1$s → modifier « %2$s »', 'lumen-wp'),
				$type_label,
				$post->post_title
			),
			'issue'      => __('Extrait manquant', 'lumen-wp'),
		];
	}

	private function mime_to_label(string $mime): string
	{
		$map = [
			'image/jpeg'      => 'JPEG',
			'image/png'       => 'PNG',
			'image/gif'       => 'GIF',
			'image/webp'      => 'WebP',
			'image/avif'      => 'AVIF',
			'video/mp4'       => 'MP4',
			'video/quicktime' => 'MOV',
			'video/x-msvideo' => 'AVI',
			'video/webm'      => 'WebM',
		];

		if (isset($map[$mime])) {
			return $map[$mime];
		}

		$part = preg_replace('#^.+/(.+)$#', '$1', $mime);

		return strtoupper(is_string($part) && $part !== '' ? $part : $mime);
	}

	/**
	 * @param list<array<string, mixed>> $items
	 */
	private function calculate_score(array $items): int
	{
		$score = 100;
		foreach ($items as $item) {
			switch ($item['severity'] ?? '') {
				case 'critical':
					$score -= 15;
					break;
				case 'warning':
					$score -= 8;
					break;
				default:
					$score -= 2;
			}
		}

		return max(0, min(100, $score));
	}
}
