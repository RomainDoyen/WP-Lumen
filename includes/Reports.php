<?php

declare(strict_types=1);

namespace LumenWp;

/**
 * Audit + history report data and download endpoint.
 */
final class Reports
{
	public function register(): void
	{
		add_action('admin_post_lumen_wp_export', [$this, 'handle_export']);
	}

	public function handle_export(): void
	{
		if (! current_user_can('upload_files')) {
			wp_die(esc_html__('Permission refusée.', 'lumen-wp'), 403);
		}
		check_admin_referer('lumen_wp_export');

		$report = sanitize_key((string) ($_GET['report'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$format = sanitize_key((string) ($_GET['format'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if (! in_array($report, ['audit', 'history'], true) || ! in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
			wp_die(esc_html__('Export invalide.', 'lumen-wp'), 400);
		}

		if ($report === 'audit') {
			$this->export_audit($format);
		}
		$this->export_history($format);
	}

	public static function export_url(string $report, string $format): string
	{
		return wp_nonce_url(
			admin_url(
				'admin-post.php?action=lumen_wp_export&report=' . rawurlencode($report) . '&format=' . rawurlencode($format)
			),
			'lumen_wp_export'
		);
	}

	/**
	 * @return array{
	 *   generated_at: string,
	 *   site: string,
	 *   summary: array<string, int|string>,
	 *   by_kind: array<string, int>,
	 *   by_mime: array<string, int>,
	 *   caps: array<string, bool>,
	 *   settings: array<string, string>,
	 *   errors: list<array{id: int, title: string, message: string, edit_url: string}>
	 * }
	 */
	public static function collect_audit(): array
	{
		$settings = Plugin::instance()->settings();
		$caps     = Plugin::capabilities();
		$stats    = self::collect_stats();
		$by_kind  = self::count_by_kind();
		$by_mime  = self::count_ok_mime();
		$seo_n    = self::count_with_meta(Plugin::META_SEO);
		$bak_n    = self::count_with_meta(Plugin::META_ORIGINAL_BACKUP);
		$errors   = self::failed_attachments(200);

		return [
			'generated_at' => wp_date('c'),
			'site'         => home_url('/'),
			'summary'      => [
				'total'      => $stats['total'],
				'ok'         => $stats['ok'],
				'pending'    => $stats['pending'],
				'error'      => $stats['error'],
				'with_seo'   => $seo_n,
				'with_backup'=> $bak_n,
			],
			'by_kind'  => $by_kind,
			'by_mime'  => $by_mime,
			'caps'     => $caps,
			'settings' => [
				'replace_original'     => ! empty($settings['replace_original']) ? 'yes' : 'no',
				'rewrite_content_urls' => ! empty($settings['rewrite_content_urls']) ? 'yes' : 'no',
				'formats'              => implode(', ', is_array($settings['formats'] ?? null) ? $settings['formats'] : []),
				'ai_provider'          => (string) ($settings['ai_provider'] ?? 'none'),
				'ui_theme'             => (string) ($settings['ui_theme'] ?? 'light'),
			],
			'errors' => $errors,
		];
	}

	private function export_audit(string $format): void
	{
		$audit = self::collect_audit();
		$stamp = wp_date('Y-m-d_His');

		$summary_headers = ['Metric', 'Value'];
		$summary_rows    = [];
		foreach ($audit['summary'] as $k => $v) {
			$summary_rows[] = [$k, $v];
		}
		$summary_rows[] = ['site', $audit['site']];
		$summary_rows[] = ['generated_at', $audit['generated_at']];

		$kind_headers = ['Kind', 'Count'];
		$kind_rows    = [];
		foreach ($audit['by_kind'] as $k => $v) {
			$kind_rows[] = [$k, $v];
		}

		$mime_headers = ['MIME', 'Count (OK)'];
		$mime_rows    = [];
		foreach ($audit['by_mime'] as $k => $v) {
			$mime_rows[] = [$k, $v];
		}

		$caps_headers = ['Capability', 'Available'];
		$caps_rows    = [];
		foreach ($audit['caps'] as $k => $v) {
			$caps_rows[] = [$k, $v ? 'yes' : 'no'];
		}

		$settings_headers = ['Setting', 'Value'];
		$settings_rows    = [];
		foreach ($audit['settings'] as $k => $v) {
			$settings_rows[] = [$k, $v];
		}

		$error_headers = ['ID', 'Title', 'Message', 'Edit URL'];
		$error_rows    = [];
		foreach ($audit['errors'] as $err) {
			$error_rows[] = [
				$err['id'],
				$err['title'],
				$err['message'],
				$err['edit_url'],
			];
		}

		if ($format === 'csv') {
			// Flatten: summary + blank + errors.
			$rows = $summary_rows;
			$rows[] = ['', ''];
			$rows[] = ['--- errors ---', ''];
			foreach ($error_rows as $er) {
				$rows[] = [$er[0] . ' | ' . $er[1], $er[2]];
			}
			Exporters::send_csv("lumen-audit-{$stamp}.csv", $summary_headers, $rows);
		}

		if ($format === 'xlsx') {
			Exporters::send_xlsx(
				"lumen-audit-{$stamp}.xlsx",
				[
					['name' => 'Summary', 'headers' => $summary_headers, 'rows' => $summary_rows],
					['name' => 'By kind', 'headers' => $kind_headers, 'rows' => $kind_rows],
					['name' => 'MIME OK', 'headers' => $mime_headers, 'rows' => $mime_rows],
					['name' => 'Capabilities', 'headers' => $caps_headers, 'rows' => $caps_rows],
					['name' => 'Settings', 'headers' => $settings_headers, 'rows' => $settings_rows],
					['name' => 'Errors', 'headers' => $error_headers, 'rows' => $error_rows],
				]
			);
		}

		$kind_labels = [
			'image' => 'Images',
			'svg'   => 'SVG',
			'pdf'   => 'PDF',
			'video' => 'Vidéos',
		];
		$kind_pairs = [];
		foreach ($audit['by_kind'] as $k => $v) {
			$kind_pairs[] = [$kind_labels[$k] ?? $k, (string) $v];
		}

		$mime_pairs = [];
		foreach ($audit['by_mime'] as $k => $v) {
			$mime_pairs[] = [$k, (string) $v];
		}

		$caps_pairs = [];
		foreach ($audit['caps'] as $k => $v) {
			$caps_pairs[] = [strtoupper($k), $v ? 'Oui' : 'Non'];
		}

		$settings_labels = [
			'replace_original'     => 'Remplacer l’original',
			'rewrite_content_urls' => 'Réécrire les URLs contenu',
			'formats'              => 'Formats',
			'ai_provider'          => 'Fournisseur IA',
			'ui_theme'             => 'Thème',
		];
		$settings_pairs = [];
		foreach ($audit['settings'] as $k => $v) {
			$settings_pairs[] = [$settings_labels[$k] ?? $k, (string) $v];
		}

		$error_items = [];
		foreach ($audit['errors'] as $err) {
			$error_items[] = '#' . $err['id'] . '  ' . $err['title'] . ' — ' . $err['message'];
		}

		Exporters::send_pdf(
			"lumen-audit-{$stamp}.pdf",
			[
				'title'    => 'Audit médiathèque',
				'subtitle' => (string) $audit['site'],
				'meta'     => [
					['label' => 'Généré le', 'value' => wp_date('d/m/Y H:i')],
					['label' => 'Version', 'value' => LUMEN_WP_VERSION],
					['label' => 'Erreurs listées', 'value' => (string) count($audit['errors'])],
				],
				'kpis'     => [
					['label' => 'Médias supportés', 'value' => (string) $audit['summary']['total'], 'tone' => 'neutral'],
					['label' => 'OK', 'value' => (string) $audit['summary']['ok'], 'tone' => 'ok'],
					['label' => 'À traiter', 'value' => (string) $audit['summary']['pending'], 'tone' => 'warn'],
					['label' => 'Erreurs', 'value' => (string) $audit['summary']['error'], 'tone' => 'error'],
				],
				'sections' => [
					[
						'title' => 'Couverture SEO & sauvegardes',
						'type'  => 'kv',
						'pairs' => [
							['Médias avec SEO', (string) $audit['summary']['with_seo']],
							['Médias avec sauvegarde original', (string) $audit['summary']['with_backup']],
						],
					],
					[
						'title'   => 'Répartition par type',
						'type'    => 'table',
						'headers' => ['Type', 'Nombre'],
						'rows'    => $kind_pairs,
					],
					[
						'title'   => 'MIME des médias OK',
						'type'    => 'table',
						'headers' => ['MIME', 'Nombre'],
						'rows'    => $mime_pairs,
					],
					[
						'title' => 'Capacités serveur',
						'type'  => 'kv',
						'pairs' => $caps_pairs,
					],
					[
						'title' => 'Réglages Lumen',
						'type'  => 'kv',
						'pairs' => $settings_pairs,
					],
					[
						'title' => 'Erreurs récentes',
						'type'  => 'list',
						'items' => $error_items,
					],
				],
			]
		);
	}

	private function export_history(string $format): void
	{
		$history = Bulk_Queue::history();
		$stamp   = wp_date('Y-m-d_His');

		$headers = [
			'ID',
			'Started',
			'Ended',
			'Status',
			'OK',
			'Errors',
			'Processed',
			'Total est.',
			'Force',
			'AI',
			'AI provider',
			'Types',
			'User',
			'Error details',
		];
		$rows = [];
		foreach ($history as $entry) {
			if (! is_array($entry)) {
				continue;
			}
			$error_bits = [];
			foreach ((array) ($entry['errors'] ?? []) as $err) {
				if (! is_array($err)) {
					continue;
				}
				$id = (int) ($err['id'] ?? 0);
				$msg = (string) ($err['message'] ?? $err['error_message'] ?? '');
				$title = (string) ($err['title'] ?? '');
				$error_bits[] = ($id > 0 ? '#' . $id . ' ' : '') . trim($title . ' ' . $msg);
			}
			$rows[] = [
				(string) ($entry['id'] ?? ''),
				(string) ($entry['started_at'] ?? ''),
				(string) ($entry['ended_at'] ?? ''),
				(string) ($entry['ended'] ?? ''),
				(int) ($entry['ok'] ?? 0),
				(int) ($entry['err'] ?? 0),
				(int) ($entry['processed'] ?? 0),
				(int) ($entry['total_estimate'] ?? 0),
				! empty($entry['force']) ? 'yes' : 'no',
				! empty($entry['use_ai']) ? 'yes' : 'no',
				(string) (($entry['ai_label'] ?? '') !== '' ? $entry['ai_label'] : ($entry['ai_provider'] ?? '')),
				implode(', ', is_array($entry['types'] ?? null) ? $entry['types'] : []),
				(string) ($entry['user_name'] ?? ''),
				implode(' | ', $error_bits),
			];
		}

		if ($format === 'csv') {
			Exporters::send_csv("lumen-history-{$stamp}.csv", $headers, $rows);
		}

		if ($format === 'xlsx') {
			Exporters::send_xlsx(
				"lumen-history-{$stamp}.xlsx",
				[
					['name' => 'History', 'headers' => $headers, 'rows' => $rows],
				]
			);
		}

		$ok_total  = 0;
		$err_total = 0;
		foreach ($rows as $row) {
			$ok_total  += (int) $row[4];
			$err_total += (int) $row[5];
		}

		$table_rows = [];
		$list_items = [];
		foreach ($rows as $row) {
			$started = self::format_report_date((string) $row[1]);
			$ended   = self::format_report_date((string) $row[2]);
			$status  = (string) $row[3] === 'done' ? 'Terminé' : 'Arrêté';
			$table_rows[] = [
				$started,
				$status,
				(string) $row[4],
				(string) $row[5],
				(string) $row[6],
				(string) $row[12] !== '' ? (string) $row[12] : '—',
			];

			$line = sprintf(
				'%s → %s · %s · OK %s / err %s / %s traités · %s · types: %s',
				$started,
				$ended,
				$status,
				$row[4],
				$row[5],
				$row[6],
				(string) $row[12] !== '' ? $row[12] : '—',
				(string) $row[11] !== '' ? $row[11] : '—'
			);
			if ((string) $row[10] !== '') {
				$line .= ' · IA: ' . $row[10];
			}
			$list_items[] = $line;
			if ((string) $row[13] !== '') {
				$list_items[] = 'Erreurs: ' . $row[13];
			}
		}

		Exporters::send_pdf(
			"lumen-history-{$stamp}.pdf",
			[
				'title'    => 'Historique des traitements',
				'subtitle' => home_url('/'),
				'meta'     => [
					['label' => 'Généré le', 'value' => wp_date('d/m/Y H:i')],
					['label' => 'Runs', 'value' => (string) count($rows) . ' / ' . Bulk_Queue::HISTORY_MAX],
					['label' => 'Version', 'value' => LUMEN_WP_VERSION],
				],
				'kpis'     => [
					['label' => 'Runs archivés', 'value' => (string) count($rows), 'tone' => 'neutral'],
					['label' => 'Total OK', 'value' => (string) $ok_total, 'tone' => 'ok'],
					['label' => 'Total erreurs', 'value' => (string) $err_total, 'tone' => 'error'],
				],
				'sections' => [
					[
						'title'   => 'Synthèse des runs',
						'type'    => 'table',
						'headers' => ['Début', 'Statut', 'OK', 'Err', 'Traités', 'Auteur'],
						'rows'    => $table_rows,
					],
					[
						'title' => 'Détail des traitements',
						'type'  => 'list',
						'items' => $list_items,
					],
				],
			]
		);
	}

	private static function format_report_date(string $iso): string
	{
		$iso = trim($iso);
		if ($iso === '') {
			return '—';
		}
		$ts = strtotime($iso);

		return $ts ? wp_date('d/m/Y H:i', $ts) : $iso;
	}

	/**
	 * @return array{total: int, ok: int, error: int, pending: int}
	 */
	private static function collect_stats(): array
	{
		global $wpdb;

		$status_key       = Plugin::META_STATUS;
		$variants_key     = Plugin::META_VARIANTS;
		$replace_original = ! empty(Plugin::instance()->settings()['replace_original']);
		$mime_sql         = Media_Types::mime_where_sql(Media_Types::all_types(), 'p');
		$img_mime         = Media_Types::mime_where_sql([Media_Types::KIND_IMAGE], 'p');
		$doc_mime         = Media_Types::mime_where_sql(
			[Media_Types::KIND_SVG, Media_Types::KIND_PDF, Media_Types::KIND_VIDEO],
			'p'
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(p.ID)
			FROM {$wpdb->posts} p
			WHERE p.post_type = 'attachment'
			  AND p.post_status = 'inherit'
			  AND {$mime_sql}"
		);

		if ($replace_original) {
			$img_ok = "(
				{$img_mime}
				AND s.meta_id IS NOT NULL
				AND v.meta_id IS NOT NULL
				AND p.post_mime_type IN ('image/webp', 'image/avif')
			)";
		} else {
			$img_ok = "(
				{$img_mime}
				AND s.meta_id IS NOT NULL
				AND v.meta_id IS NOT NULL
			)";
		}

		$ok = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} s
					ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = 'ok'
				LEFT JOIN {$wpdb->postmeta} v
					ON v.post_id = p.ID AND v.meta_key = %s AND v.meta_value != '' AND v.meta_value != 'a:0:{}'
				WHERE p.post_type = 'attachment'
				  AND p.post_status = 'inherit'
				  AND (
					{$img_ok}
					OR ({$doc_mime} AND s.meta_id IS NOT NULL)
				  )",
				$status_key,
				$variants_key
			)
		);

		$error = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} s
					ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = 'error'
				WHERE p.post_type = 'attachment'
				  AND p.post_status = 'inherit'
				  AND {$mime_sql}",
				$status_key
			)
		);
		// phpcs:enable

		return [
			'total'   => $total,
			'ok'      => $ok,
			'error'   => $error,
			'pending' => max(0, $total - $ok),
		];
	}

	/**
	 * @return array<string, int>
	 */
	private static function count_by_kind(): array
	{
		global $wpdb;
		$out = [
			Media_Types::KIND_IMAGE => 0,
			Media_Types::KIND_SVG   => 0,
			Media_Types::KIND_PDF   => 0,
			Media_Types::KIND_VIDEO => 0,
		];

		foreach (array_keys($out) as $kind) {
			$sql = Media_Types::mime_where_sql([$kind], 'p');
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[$kind] = (int) $wpdb->get_var(
				"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
				WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND {$sql}"
			);
			// phpcs:enable
		}

		return $out;
	}

	/**
	 * @return array<string, int>
	 */
	private static function count_ok_mime(): array
	{
		global $wpdb;
		$status = Plugin::META_STATUS;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.post_mime_type AS mime, COUNT(*) AS n
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} s
					ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = 'ok'
				WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
				GROUP BY p.post_mime_type
				ORDER BY n DESC
				LIMIT 30",
				$status
			),
			ARRAY_A
		);
		// phpcs:enable

		$out = [];
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$mime = (string) ($row['mime'] ?? '');
				if ($mime === '') {
					$mime = '(empty)';
				}
				$out[$mime] = (int) ($row['n'] ?? 0);
			}
		}

		return $out;
	}

	private static function count_with_meta(string $meta_key): int
	{
		global $wpdb;
		$mime_sql = Media_Types::mime_where_sql(Media_Types::all_types(), 'p');
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value != ''
				WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND {$mime_sql}",
				$meta_key
			)
		);
		// phpcs:enable
	}

	/**
	 * @return list<array{id: int, title: string, message: string, edit_url: string}>
	 */
	private static function failed_attachments(int $limit): array
	{
		global $wpdb;
		$status   = Plugin::META_STATUS;
		$error_k  = Plugin::META_ERROR;
		$mime_sql = Media_Types::mime_where_sql(Media_Types::all_types(), 'p');
		$limit    = max(1, min(500, $limit));

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, e.meta_value AS error_message
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} s
					ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = 'error'
				LEFT JOIN {$wpdb->postmeta} e
					ON e.post_id = p.ID AND e.meta_key = %s
				WHERE p.post_type = 'attachment'
				  AND p.post_status = 'inherit'
				  AND {$mime_sql}
				ORDER BY p.ID DESC
				LIMIT %d",
				$status,
				$error_k,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable

		$out = [];
		if (! is_array($rows)) {
			return $out;
		}
		foreach ($rows as $row) {
			$id    = (int) ($row['ID'] ?? 0);
			$title = (string) ($row['post_title'] ?? '');
			$out[] = [
				'id'       => $id,
				'title'    => $title !== '' ? $title : '#' . $id,
				'message'  => (string) ($row['error_message'] ?? ''),
				'edit_url' => Bulk_Queue::edit_url_for($id),
			];
		}

		return $out;
	}
}
