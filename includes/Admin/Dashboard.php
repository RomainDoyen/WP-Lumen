<?php

declare(strict_types=1);

namespace LumenWp\Admin;

use LumenWp\Bulk_Queue;
use LumenWp\Icon_Kit;
use LumenWp\Media_Types;
use LumenWp\Plugin;
use LumenWp\Vision_Ai;

final class Dashboard
{
	public function register(): void
	{
		add_action('admin_menu', [$this, 'add_menu'], 9);
	}

	public function add_menu(): void
	{
		add_menu_page(
			__('Lumen', 'lumen-wp'),
			__('Lumen', 'lumen-wp'),
			'upload_files',
			'lumen-wp',
			[$this, 'render_page'],
			Brand::menu_icon(),
			58
		);

		add_submenu_page(
			'lumen-wp',
			__('Dashboard Lumen', 'lumen-wp'),
			__('Dashboard', 'lumen-wp'),
			'upload_files',
			'lumen-wp',
			[$this, 'render_page']
		);
	}

	public function render_page(): void
	{
		if (! current_user_can('upload_files')) {
			return;
		}

		$stats    = $this->collect_stats();
		$failed   = $stats['error'] > 0 ? $this->failed_attachments(50) : [];
		$caps     = Plugin::capabilities();
		$settings = Plugin::instance()->settings();
		$icons    = Icon_Kit::stored();
		$usage    = Vision_Ai::usage();
		$ai_prov  = Vision_Ai::active_provider();
		$budget   = (int) ($settings['ai_budget_month'] ?? 0);
		$consoles = [
			'mistral'   => 'https://console.mistral.ai/',
			'openai'    => 'https://platform.openai.com/usage',
			'anthropic' => 'https://console.anthropic.com/',
			'gemini'    => 'https://aistudio.google.com/',
		];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$recent_page = isset($_GET['lumen_rp']) ? max(1, (int) $_GET['lumen_rp']) : 1;
		$recent      = $this->recent_processed(5, $recent_page);

		?>
		<div class="wrap lumen-wp-wrap">
			<?php
			Brand::render_nav('dashboard');
			Brand::render_header(
				__('Dashboard', 'lumen-wp'),
				__('Vue d’ensemble des médias, du SEO et des icônes.', 'lumen-wp')
			);
			?>

			<section class="lumen-wp-dash-stats">
				<article class="lumen-wp-stat">
					<span class="lumen-wp-stat__label"><?php esc_html_e('Médias OK', 'lumen-wp'); ?></span>
					<strong class="lumen-wp-stat__value"><?php echo esc_html((string) $stats['ok']); ?></strong>
				</article>
				<article class="lumen-wp-stat">
					<span class="lumen-wp-stat__label"><?php esc_html_e('À traiter', 'lumen-wp'); ?></span>
					<strong class="lumen-wp-stat__value"><?php echo esc_html((string) $stats['pending']); ?></strong>
				</article>
				<?php if ((int) $stats['error'] > 0) : ?>
					<a class="lumen-wp-stat lumen-wp-stat--link" href="#lumen-wp-failed-media">
						<span class="lumen-wp-stat__label"><?php esc_html_e('Erreurs', 'lumen-wp'); ?></span>
						<strong class="lumen-wp-stat__value"><?php echo esc_html((string) $stats['error']); ?></strong>
						<span class="lumen-wp-stat__hint"><?php esc_html_e('Voir la liste', 'lumen-wp'); ?></span>
					</a>
				<?php else : ?>
					<article class="lumen-wp-stat">
						<span class="lumen-wp-stat__label"><?php esc_html_e('Erreurs', 'lumen-wp'); ?></span>
						<strong class="lumen-wp-stat__value"><?php echo esc_html((string) $stats['error']); ?></strong>
					</article>
				<?php endif; ?>
				<article class="lumen-wp-stat">
					<span class="lumen-wp-stat__label"><?php esc_html_e('Médias supportés', 'lumen-wp'); ?></span>
					<strong class="lumen-wp-stat__value"><?php echo esc_html((string) $stats['total']); ?></strong>
				</article>
				<article class="lumen-wp-stat">
					<span class="lumen-wp-stat__label"><?php esc_html_e('Appels IA / mois', 'lumen-wp'); ?></span>
					<strong class="lumen-wp-stat__value"><?php echo esc_html(number_format_i18n((int) $usage['calls_month'])); ?></strong>
					<span class="lumen-wp-stat__hint">
						<?php
						echo $budget > 0
							? esc_html(sprintf(
								/* translators: %s: monthly budget */
								__('Budget %s', 'lumen-wp'),
								number_format_i18n($budget)
							))
							: esc_html__('Sans plafond Lumen', 'lumen-wp');
						?>
					</span>
				</article>
			</section>

			<section class="lumen-wp-dash-grid">
				<a class="lumen-wp-dash-card" href="<?php echo esc_url(admin_url('admin.php?page=lumen-wp-bulk')); ?>">
					<span class="lumen-wp-dash-card__eyebrow"><?php esc_html_e('Médiathèque', 'lumen-wp'); ?></span>
					<h2 class="lumen-wp-dash-card__title"><?php esc_html_e('Traitement', 'lumen-wp'); ?></h2>
					<p class="lumen-wp-dash-card__text">
						<?php
						printf(
							/* translators: %d: pending count */
							esc_html(_n('%d média à traiter', '%d médias à traiter', $stats['pending'], 'lumen-wp')),
							(int) $stats['pending']
						);
						?>
					</p>
				</a>

				<a class="lumen-wp-dash-card" href="<?php echo esc_url(admin_url('admin.php?page=lumen-wp-icons')); ?>">
					<span class="lumen-wp-dash-card__eyebrow"><?php esc_html_e('Identité', 'lumen-wp'); ?></span>
					<h2 class="lumen-wp-dash-card__title"><?php esc_html_e('Icônes & favicons', 'lumen-wp'); ?></h2>
					<p class="lumen-wp-dash-card__text">
						<?php
						echo ! empty($icons['kit'])
							? esc_html__('Kit généré — prêt à télécharger ou appliquer.', 'lumen-wp')
							: esc_html__('Générez un kit PNG multi-tailles pour le site.', 'lumen-wp');
						?>
					</p>
				</a>

				<?php if (current_user_can('manage_options')) : ?>
					<a class="lumen-wp-dash-card" href="<?php echo esc_url(admin_url('admin.php?page=lumen-wp-settings')); ?>">
						<span class="lumen-wp-dash-card__eyebrow"><?php esc_html_e('Configuration', 'lumen-wp'); ?></span>
						<h2 class="lumen-wp-dash-card__title"><?php esc_html_e('Réglages', 'lumen-wp'); ?></h2>
						<p class="lumen-wp-dash-card__text">
							<?php
							$formats = is_array($settings['formats'] ?? null) ? $settings['formats'] : [];
							echo esc_html(
								sprintf(
									/* translators: %s: formats list */
									__('Formats : %s', 'lumen-wp'),
									strtoupper(implode(' · ', $formats))
								)
							);
							?>
						</p>
					</a>
				<?php endif; ?>

				<a class="lumen-wp-dash-card" href="<?php echo esc_url(admin_url('upload.php')); ?>">
					<span class="lumen-wp-dash-card__eyebrow"><?php esc_html_e('WordPress', 'lumen-wp'); ?></span>
					<h2 class="lumen-wp-dash-card__title"><?php esc_html_e('Médias', 'lumen-wp'); ?></h2>
					<p class="lumen-wp-dash-card__text"><?php esc_html_e('Ouvrir la médiathèque pour le SEO par média.', 'lumen-wp'); ?></p>
				</a>
			</section>

			<?php if ($failed !== []) : ?>
				<section class="lumen-wp-panel lumen-wp-failed-panel" id="lumen-wp-failed-media">
					<h2 class="lumen-wp-panel__title"><?php esc_html_e('Médias en erreur', 'lumen-wp'); ?></h2>
					<p class="description">
						<?php
						printf(
							/* translators: %d: error count */
							esc_html(_n('%d média à corriger — cliquez pour ouvrir la fiche.', '%d médias à corriger — cliquez pour ouvrir la fiche.', count($failed), 'lumen-wp')),
							count($failed)
						);
						?>
					</p>
					<ul class="lumen-wp-error-list">
						<?php foreach ($failed as $row) : ?>
							<li class="lumen-wp-error-list__item">
								<a class="lumen-wp-error-list__title" href="<?php echo esc_url($row['edit_url']); ?>">
									<?php echo esc_html($row['title']); ?>
								</a>
								<?php if ($row['message'] !== '') : ?>
									<span class="lumen-wp-error-list__msg"><?php echo esc_html($row['message']); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<section class="lumen-wp-panel lumen-wp-panel--recent">
				<header class="lumen-wp-recent-head">
					<h2 class="lumen-wp-panel__title"><?php esc_html_e('Derniers médias traités', 'lumen-wp'); ?></h2>
					<?php if ($recent['total'] > 0) : ?>
						<p class="lumen-wp-recent-meta">
							<?php
							printf(
								/* translators: 1: current page, 2: total pages, 3: formatted total items */
								esc_html__('Page %1$s / %2$s · %3$s média(s)', 'lumen-wp'),
								esc_html(number_format_i18n((int) $recent['page'])),
								esc_html(number_format_i18n((int) $recent['pages'])),
								esc_html(number_format_i18n((int) $recent['total']))
							);
							?>
						</p>
					<?php endif; ?>
				</header>

				<div class="lumen-wp-recent-body">
					<?php if ($recent['items'] === []) : ?>
						<p class="lumen-wp-recent-empty"><?php esc_html_e('Aucun média traité pour le moment.', 'lumen-wp'); ?></p>
					<?php else : ?>
						<ul class="lumen-wp-dash-recent">
							<?php foreach ($recent['items'] as $row) : ?>
								<li>
									<a href="<?php echo esc_url((string) get_edit_post_link((int) $row['ID'])); ?>">
										<?php echo esc_html($row['title'] !== '' ? $row['title'] : '#' . $row['ID']); ?>
									</a>
									<span><?php echo esc_html($row['date']); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>

						<?php if ($recent['pages'] > 1) : ?>
							<nav class="lumen-wp-recent-pager" aria-label="<?php esc_attr_e('Pagination des médias traités', 'lumen-wp'); ?>">
								<?php
								$base  = admin_url('admin.php?page=lumen-wp');
								$curr  = (int) $recent['page'];
								$pages = (int) $recent['pages'];
								$slots = $this->pagination_slots($curr, $pages);
								?>
								<a
									class="button lumen-wp-pager-btn<?php echo $curr <= 1 ? ' disabled' : ''; ?>"
									href="<?php echo $curr <= 1 ? '#' : esc_url(add_query_arg('lumen_rp', $curr - 1, $base)); ?>"
									<?php echo $curr <= 1 ? ' aria-disabled="true" tabindex="-1"' : ''; ?>
									aria-label="<?php esc_attr_e('Page précédente', 'lumen-wp'); ?>"
									title="<?php esc_attr_e('Précédent', 'lumen-wp'); ?>"
								>
									<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" aria-hidden="true" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
								</a>

								<ul class="lumen-wp-recent-pages">
									<?php foreach ($slots as $slot) : ?>
										<?php if ($slot === null) : ?>
											<li class="lumen-wp-recent-ellipsis" aria-hidden="true">…</li>
										<?php else : ?>
											<li>
												<a
													class="lumen-wp-recent-page<?php echo $slot === $curr ? ' is-active' : ''; ?>"
													href="<?php echo esc_url(add_query_arg('lumen_rp', $slot, $base)); ?>"
													<?php echo $slot === $curr ? ' aria-current="page"' : ''; ?>
													aria-label="<?php
														printf(
															/* translators: %s: page number */
															esc_attr__('Page %s', 'lumen-wp'),
															number_format_i18n($slot)
														);
													?>"
												><?php echo esc_html(number_format_i18n($slot)); ?></a>
											</li>
										<?php endif; ?>
									<?php endforeach; ?>
								</ul>

								<a
									class="button lumen-wp-pager-btn<?php echo $curr >= $pages ? ' disabled' : ''; ?>"
									href="<?php echo $curr >= $pages ? '#' : esc_url(add_query_arg('lumen_rp', $curr + 1, $base)); ?>"
									<?php echo $curr >= $pages ? ' aria-disabled="true" tabindex="-1"' : ''; ?>
									aria-label="<?php esc_attr_e('Page suivante', 'lumen-wp'); ?>"
									title="<?php esc_attr_e('Suivant', 'lumen-wp'); ?>"
								>
									<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" aria-hidden="true" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
								</a>
							</nav>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</section>

			<section class="lumen-wp-dash-footer">
				<section class="lumen-wp-panel lumen-wp-panel--ai-usage">
					<h2 class="lumen-wp-panel__title"><?php esc_html_e('Usage IA (compteur local)', 'lumen-wp'); ?></h2>
					<div class="lumen-wp-ai-usage-grid">
						<div>
							<span class="lumen-wp-stat__label"><?php esc_html_e('Ce mois', 'lumen-wp'); ?></span>
							<strong><?php echo esc_html(number_format_i18n((int) $usage['calls_month'])); ?></strong>
						</div>
						<div>
							<span class="lumen-wp-stat__label"><?php esc_html_e('Total', 'lumen-wp'); ?></span>
							<strong><?php echo esc_html(number_format_i18n((int) $usage['total_calls'])); ?></strong>
						</div>
						<div>
							<span class="lumen-wp-stat__label"><?php esc_html_e('Rate limits', 'lumen-wp'); ?></span>
							<strong><?php echo esc_html(number_format_i18n((int) $usage['rate_limits'])); ?></strong>
						</div>
						<div>
							<span class="lumen-wp-stat__label"><?php esc_html_e('Fournisseur', 'lumen-wp'); ?></span>
							<strong><?php echo esc_html(Vision_Ai::provider_label($ai_prov)); ?></strong>
						</div>
					</div>
					<?php if (! empty($usage['last_error'])) : ?>
						<p class="lumen-wp-ai-usage-error"><?php echo esc_html((string) $usage['last_error']); ?></p>
					<?php endif; ?>
					<p class="lumen-wp-actions-row">
						<?php if (current_user_can('manage_options')) : ?>
							<button type="button" class="button" id="lumen-wp-ai-usage-reset">
								<?php esc_html_e('Réinitialiser', 'lumen-wp'); ?>
							</button>
							<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lumen-wp-settings')); ?>">
								<?php esc_html_e('Réglages IA', 'lumen-wp'); ?>
							</a>
						<?php endif; ?>
						<?php if (isset($consoles[$ai_prov])) : ?>
							<a class="button" href="<?php echo esc_url($consoles[$ai_prov]); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e('Console', 'lumen-wp'); ?>
							</a>
						<?php endif; ?>
					</p>
				</section>

				<section class="lumen-wp-panel">
					<h2 class="lumen-wp-panel__title"><?php esc_html_e('État serveur', 'lumen-wp'); ?></h2>
					<div class="lumen-wp-dash-caps">
						<span class="<?php echo $caps['imagick'] ? 'is-ok' : 'is-no'; ?>">Imagick</span>
						<span class="<?php echo $caps['gd'] ? 'is-ok' : 'is-no'; ?>">GD</span>
						<span class="<?php echo $caps['webp'] ? 'is-ok' : 'is-no'; ?>">WebP</span>
						<span class="<?php echo $caps['avif'] ? 'is-ok' : 'is-no'; ?>">AVIF</span>
						<span class="<?php echo ! empty($settings['site_favicons']) ? 'is-ok' : 'is-no'; ?>">
							<?php esc_html_e('Favicons site', 'lumen-wp'); ?>
						</span>
						<span class="<?php echo ! empty($settings['auto_on_upload']) ? 'is-ok' : 'is-no'; ?>">
							<?php esc_html_e('Auto upload', 'lumen-wp'); ?>
						</span>
						<span class="<?php echo Vision_Ai::is_configured() ? 'is-ok' : 'is-no'; ?>">
							<?php echo esc_html('IA · ' . Vision_Ai::provider_label($ai_prov)); ?>
						</span>
					</div>
				</section>
			</section>
		</div>
		<?php
	}

	/**
	 * Fenêtre de pagination compacte : 1 … 8 9 10 … 240
	 *
	 * @return list<int|null> null = ellipse
	 */
	private function pagination_slots(int $current, int $total, int $siblings = 1): array
	{
		$current  = max(1, $current);
		$total    = max(1, $total);
		$siblings = max(0, $siblings);

		if ($total <= 7) {
			return range(1, $total);
		}

		$slots = [];
		$start = max(2, $current - $siblings);
		$end   = min($total - 1, $current + $siblings);

		$slots[] = 1;

		if ($start > 2) {
			$slots[] = null;
		} elseif ($start === 2) {
			// Pas d’ellipse : on peut coller la page 2.
		}

		for ($i = $start; $i <= $end; $i++) {
			$slots[] = $i;
		}

		if ($end < $total - 1) {
			$slots[] = null;
		}

		$slots[] = $total;

		return $slots;
	}

	/**
	 * @return array{
	 *   items: list<array{ID: int, title: string, date: string}>,
	 *   total: int,
	 *   page: int,
	 *   pages: int,
	 *   per_page: int
	 * }
	 */
	/**
	 * Médias avec statut Lumen « error », pour la liste dashboard.
	 *
	 * @return list<array{id: int, title: string, message: string, edit_url: string}>
	 */
	private function failed_attachments(int $limit = 50): array
	{
		global $wpdb;

		$limit    = max(1, min(100, $limit));
		$status   = Plugin::META_STATUS;
		$error    = Plugin::META_ERROR;
		$mime_sql = Media_Types::mime_where_sql(Media_Types::all_types(), 'p');

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
				ORDER BY p.post_modified DESC
				LIMIT %d",
				$status,
				$error,
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
			$title = trim((string) ($row['post_title'] ?? ''));
			if ($title === '' && $id > 0) {
				$file  = get_attached_file($id);
				$title = is_string($file) && $file !== '' ? basename($file) : '#' . $id;
			}
			$out[] = [
				'id'       => $id,
				'title'    => $title !== '' ? $title : '#' . $id,
				'message'  => (string) ($row['error_message'] ?? ''),
				'edit_url' => Bulk_Queue::edit_url_for($id),
			];
		}

		return $out;
	}

	private function recent_processed(int $per_page = 5, int $page = 1): array
	{
		global $wpdb;

		$per_page = max(1, $per_page);
		$page     = max(1, $page);
		$offset   = ($page - 1) * $per_page;
		$replace  = ! empty(Plugin::instance()->settings()['replace_original']);
		$status   = Plugin::META_STATUS;
		$variants = Plugin::META_VARIANTS;
		$img_mime = Media_Types::mime_where_sql([Media_Types::KIND_IMAGE], 'p');
		$doc_mime = Media_Types::mime_where_sql(
			[Media_Types::KIND_SVG, Media_Types::KIND_PDF, Media_Types::KIND_VIDEO],
			'p'
		);

		if ($replace) {
			$img_ok = "(
				{$img_mime}
				AND v.meta_id IS NOT NULL
				AND p.post_mime_type IN ('image/webp', 'image/avif')
			)";
		} else {
			$img_ok = "(
				{$img_mime}
				AND v.meta_id IS NOT NULL
			)";
		}

		$done_sql = "(
			{$img_ok}
			OR ({$doc_mime})
		)";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} s
					ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = 'ok'
				LEFT JOIN {$wpdb->postmeta} v
					ON v.post_id = p.ID AND v.meta_key = %s AND v.meta_value != '' AND v.meta_value != 'a:0:{}'
				WHERE p.post_type = 'attachment'
				  AND p.post_status = 'inherit'
				  AND {$done_sql}",
				$status,
				$variants
			)
		);

		$pages = max(1, (int) ceil($total / $per_page));
		if ($page > $pages) {
			$page   = $pages;
			$offset = ($page - 1) * $per_page;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_date
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} s
					ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = 'ok'
				LEFT JOIN {$wpdb->postmeta} v
					ON v.post_id = p.ID AND v.meta_key = %s AND v.meta_value != '' AND v.meta_value != 'a:0:{}'
				WHERE p.post_type = 'attachment'
				  AND p.post_status = 'inherit'
				  AND {$done_sql}
				ORDER BY p.post_date DESC
				LIMIT %d OFFSET %d",
				$status,
				$variants,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable

		$items = [];
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$items[] = [
					'ID'    => (int) $row['ID'],
					'title' => (string) $row['post_title'],
					'date'  => (string) $row['post_date'],
				];
			}
		}

		return [
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'pages'    => $pages,
			'per_page' => $per_page,
		];
	}

	/**
	 * Compteurs SQL.
	 * En mode « remplacer l’original » (défaut) : OK = WebP/AVIF principal.
	 * Les JPEG/PNG restent « à traiter » même s’ils ont des sidecars.
	 *
	 * @return array{total: int, ok: int, error: int, pending: int}
	 */
	private function collect_stats(): array
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

		$pending = max(0, $total - $ok);

		return [
			'total'   => $total,
			'ok'      => $ok,
			'error'   => $error,
			'pending' => $pending,
		];
	}
}
