<?php

declare(strict_types=1);

namespace LumenWp\Admin;

use LumenWp\Bulk_Queue;
use LumenWp\Job_Repository;
use LumenWp\Media_Types;
use LumenWp\Plugin;
use LumenWp\Server_Caps;
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
		$caps     = Server_Caps::detect();
		$settings = Plugin::instance()->settings();
		$usage    = Vision_Ai::usage();
		$tokens_month = Job_Repository::sum_tokens_month();
		$ai_prov  = Vision_Ai::active_provider();
		$budget   = (int) ($settings['ai_budget_month'] ?? 0);
		$pending_validation = Validation::pending_count();
		$bulk_job = Bulk_Queue::job();
		$bulk_status = (string) ($bulk_job['status'] ?? 'idle');
		$last_audit = get_option(\LumenWp\Seo_Geo_Auditor::OPTION_LAST);
		$audit_score = is_array($last_audit) ? (int) ($last_audit['score'] ?? 0) : null;
		$consoles = [
			'mistral'   => 'https://console.mistral.ai/',
			'openai'    => 'https://platform.openai.com/usage',
			'anthropic' => 'https://console.anthropic.com/',
			'gemini'    => 'https://aistudio.google.com/',
		];

		?>
		<div class="wrap lumen-wp-wrap">
			<?php
			Brand::render_nav('dashboard');
			ob_start();
			if ($pending_validation > 0) {
				echo '<a class="button button-primary" href="' . esc_url(Validation::tab_url()) . '">';
				Brand::icon('validate');
				esc_html_e('Valider', 'lumen-wp');
				echo '</a>';
			} else {
				echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=lumen-wp-bulk&tab=launch')) . '">';
				Brand::icon('bulk');
				esc_html_e('Lancer le traitement', 'lumen-wp');
				echo '</a>';
			}
			$header_actions = (string) ob_get_clean();
			Brand::render_header(
				__('Dashboard', 'lumen-wp'),
				__('Vue d’ensemble — traitement, validation, audit et serveur.', 'lumen-wp'),
				'',
				$header_actions
			);
			?>

			<?php
			$catalog = max(0, (int) $stats['total']);
			$ok_n    = max(0, (int) $stats['ok']);
			$err_n   = max(0, (int) $stats['error']);
			$pend_n  = max(0, (int) $stats['pending']);
			$ok_pct  = $catalog > 0 ? (int) round(100 * $ok_n / $catalog) : 0;
			?>
			<section class="lumen-wp-dash-stats">
				<article class="lumen-wp-stat">
					<span class="lumen-wp-stat__icon"><?php Brand::icon('validate'); ?></span>
					<span class="lumen-wp-stat__label"><?php esc_html_e('Médias OK', 'lumen-wp'); ?></span>
					<strong class="lumen-wp-stat__value"><?php echo esc_html((string) $ok_n); ?></strong>
					<span class="lumen-wp-stat__hint">
						<?php
						echo $catalog > 0
							? esc_html(sprintf(
								/* translators: %s: percentage of the catalog */
								__('%s %% du catalogue', 'lumen-wp'),
								number_format_i18n($ok_pct)
							))
							: esc_html__('Aucun média', 'lumen-wp');
						?>
					</span>
				</article>
				<article class="lumen-wp-stat">
					<span class="lumen-wp-stat__icon"><?php Brand::icon('bulk'); ?></span>
					<span class="lumen-wp-stat__label"><?php esc_html_e('À traiter', 'lumen-wp'); ?></span>
					<strong class="lumen-wp-stat__value"><?php echo esc_html((string) $pend_n); ?></strong>
					<span class="lumen-wp-stat__hint"><?php esc_html_e('En file d’attente', 'lumen-wp'); ?></span>
				</article>
				<?php if ($err_n > 0) : ?>
					<a class="lumen-wp-stat lumen-wp-stat--link" href="#lumen-wp-failed-media">
						<span class="lumen-wp-stat__icon"><?php Brand::icon('alert'); ?></span>
						<span class="lumen-wp-stat__label"><?php esc_html_e('Erreurs', 'lumen-wp'); ?></span>
						<strong class="lumen-wp-stat__value"><?php echo esc_html((string) $err_n); ?></strong>
						<span class="lumen-wp-stat__hint"><?php esc_html_e('Voir la liste', 'lumen-wp'); ?></span>
					</a>
				<?php else : ?>
					<article class="lumen-wp-stat">
						<span class="lumen-wp-stat__icon"><?php Brand::icon('alert'); ?></span>
						<span class="lumen-wp-stat__label"><?php esc_html_e('Erreurs', 'lumen-wp'); ?></span>
						<strong class="lumen-wp-stat__value"><?php echo esc_html((string) $err_n); ?></strong>
						<span class="lumen-wp-stat__hint"><?php esc_html_e('Rien à corriger', 'lumen-wp'); ?></span>
					</article>
				<?php endif; ?>
				<?php if ($pending_validation > 0) : ?>
					<a class="lumen-wp-stat lumen-wp-stat--link" href="<?php echo esc_url(Validation::tab_url()); ?>">
						<span class="lumen-wp-stat__icon"><?php Brand::icon('validate'); ?></span>
						<span class="lumen-wp-stat__label"><?php esc_html_e('À valider', 'lumen-wp'); ?></span>
						<strong class="lumen-wp-stat__value"><?php echo esc_html((string) $pending_validation); ?></strong>
						<span class="lumen-wp-stat__hint"><?php esc_html_e('Ouvrir', 'lumen-wp'); ?></span>
					</a>
				<?php else : ?>
					<article class="lumen-wp-stat">
						<span class="lumen-wp-stat__icon"><?php Brand::icon('image'); ?></span>
						<span class="lumen-wp-stat__label"><?php esc_html_e('Médias supportés', 'lumen-wp'); ?></span>
						<strong class="lumen-wp-stat__value"><?php echo esc_html((string) $catalog); ?></strong>
						<span class="lumen-wp-stat__hint"><?php esc_html_e('Images, PDF, SVG, vidéo', 'lumen-wp'); ?></span>
					</article>
				<?php endif; ?>
				<article class="lumen-wp-stat">
					<span class="lumen-wp-stat__icon"><?php Brand::icon('bolt'); ?></span>
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

			<div class="lumen-wp-split">
				<div class="lumen-wp-split__main">
			<section class="lumen-wp-panel lumen-wp-panel--next">
				<h2 class="lumen-wp-panel__title"><?php esc_html_e('Prochaines actions', 'lumen-wp'); ?></h2>
				<ul class="lumen-wp-next-actions">
					<?php if ($pending_validation > 0) : ?>
						<li>
							<span class="lumen-wp-next-actions__label">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: count */
										_n('%d média à valider (IA)', '%d médias à valider (IA)', $pending_validation, 'lumen-wp'),
										$pending_validation
									)
								);
								?>
							</span>
							<a class="button button-primary" href="<?php echo esc_url(Validation::tab_url()); ?>"><?php Brand::icon('validate'); ?><?php esc_html_e('Valider', 'lumen-wp'); ?></a>
						</li>
					<?php endif; ?>
					<?php if (in_array($bulk_status, ['running', 'paused'], true)) : ?>
						<li>
							<span class="lumen-wp-next-actions__label">
								<?php
								echo esc_html(
									$bulk_status === 'paused'
										? __('Un traitement est en pause.', 'lumen-wp')
										: __('Un traitement tourne en arrière-plan.', 'lumen-wp')
								);
								?>
							</span>
							<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lumen-wp-bulk&tab=launch')); ?>"><?php Brand::icon('bulk'); ?><?php esc_html_e('Suivre la file', 'lumen-wp'); ?></a>
						</li>
					<?php elseif ((int) $stats['pending'] > 0) : ?>
						<li>
							<span class="lumen-wp-next-actions__label">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: count */
										_n('%d média encore à optimiser', '%d médias encore à optimiser', (int) $stats['pending'], 'lumen-wp'),
										(int) $stats['pending']
									)
								);
								?>
							</span>
							<a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=lumen-wp-bulk&tab=launch')); ?>"><?php Brand::icon('bulk'); ?><?php esc_html_e('Lancer', 'lumen-wp'); ?></a>
						</li>
					<?php endif; ?>
					<?php if ((int) $stats['error'] > 0) : ?>
						<li>
							<span class="lumen-wp-next-actions__label">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: count */
										_n('%d média en erreur', '%d médias en erreur', (int) $stats['error'], 'lumen-wp'),
										(int) $stats['error']
									)
								);
								?>
							</span>
							<a class="button" href="#lumen-wp-failed-media"><?php Brand::icon('alert'); ?><?php esc_html_e('Voir les erreurs', 'lumen-wp'); ?></a>
						</li>
					<?php endif; ?>
					<?php if (current_user_can('manage_options') && $audit_score === null) : ?>
						<li>
							<span class="lumen-wp-next-actions__label"><?php esc_html_e('Aucun audit SEO/GEO lancé pour l’instant.', 'lumen-wp'); ?></span>
							<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lumen-wp-audit')); ?>"><?php Brand::icon('audit'); ?><?php esc_html_e('Lancer un audit', 'lumen-wp'); ?></a>
						</li>
					<?php endif; ?>
					<?php if (
						$pending_validation === 0
						&& ! in_array($bulk_status, ['running', 'paused'], true)
						&& (int) $stats['pending'] === 0
						&& (int) $stats['error'] === 0
						&& ! (current_user_can('manage_options') && $audit_score === null)
					) : ?>
						<li>
							<span class="lumen-wp-next-actions__label"><?php esc_html_e('Rien d’urgent — consultez l’historique des médias.', 'lumen-wp'); ?></span>
							<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lumen-wp-history')); ?>"><?php Brand::icon('history'); ?><?php esc_html_e('Ouvrir Historique', 'lumen-wp'); ?></a>
						</li>
					<?php endif; ?>
				</ul>
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

			<?php
			$runs = array_slice(Bulk_Queue::history(), 0, 6);
			$runs_url = admin_url('admin.php?page=lumen-wp-bulk&tab=launch#lumen-wp-bulk-history-panel');
			?>
			<section class="lumen-wp-panel lumen-wp-dash-runs">
				<div class="lumen-wp-dash-runs__head">
					<h2 class="lumen-wp-panel__title"><?php esc_html_e('Derniers traitements', 'lumen-wp'); ?></h2>
					<a class="button" href="<?php echo esc_url($runs_url); ?>"><?php Brand::icon('forward'); ?><?php esc_html_e('Voir tout', 'lumen-wp'); ?></a>
				</div>
				<ul class="lumen-wp-history">
					<?php if ($runs === []) : ?>
						<li class="lumen-wp-history__empty"><?php esc_html_e('Aucun traitement terminé pour le moment.', 'lumen-wp'); ?></li>
					<?php else : ?>
						<?php foreach ($runs as $entry) : ?>
							<?php echo Bulk::render_history_item($entry); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</ul>
			</section>
				</div>
				<div class="lumen-wp-split__aside">
					<ul class="lumen-wp-dash-links">
						<li>
							<a href="<?php echo esc_url(admin_url('admin.php?page=lumen-wp-bulk&tab=launch')); ?>">
								<?php Brand::icon('bulk'); ?>
								<span><?php esc_html_e('Traitement', 'lumen-wp'); ?></span>
							</a>
						</li>
						<li>
							<a href="<?php echo esc_url(admin_url('admin.php?page=lumen-wp-history')); ?>">
								<?php Brand::icon('history'); ?>
								<span><?php esc_html_e('Historique', 'lumen-wp'); ?></span>
							</a>
						</li>
						<?php if (current_user_can('manage_options')) : ?>
							<li>
								<a href="<?php echo esc_url(admin_url('admin.php?page=lumen-wp-audit')); ?>">
									<?php Brand::icon('audit'); ?>
									<span><?php esc_html_e('Audit', 'lumen-wp'); ?></span>
								</a>
							</li>
						<?php endif; ?>
						<li>
							<a href="<?php echo esc_url(admin_url('admin.php?page=lumen-wp-icons')); ?>">
								<?php Brand::icon('icons'); ?>
								<span><?php esc_html_e('Icônes & favicons', 'lumen-wp'); ?></span>
							</a>
						</li>
					</ul>
				<section class="lumen-wp-panel lumen-wp-panel--ai-usage">
					<h2 class="lumen-wp-panel__title"><?php esc_html_e('Usage IA (compteur local)', 'lumen-wp'); ?></h2>
					<div class="lumen-wp-ai-usage-grid">
						<div>
							<span class="lumen-wp-stat__label"><?php esc_html_e('Ce mois', 'lumen-wp'); ?></span>
							<strong><?php echo esc_html(number_format_i18n((int) $usage['calls_month'])); ?></strong>
						</div>
						<div>
							<span class="lumen-wp-stat__label"><?php esc_html_e('Tokens (mois)', 'lumen-wp'); ?></span>
							<strong><?php echo esc_html(number_format_i18n($tokens_month)); ?></strong>
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
								<?php Brand::icon('refresh'); ?>
								<?php esc_html_e('Réinitialiser', 'lumen-wp'); ?>
							</button>
							<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lumen-wp-settings')); ?>">
								<?php Brand::icon('settings'); ?>
								<?php esc_html_e('Réglages IA', 'lumen-wp'); ?>
							</a>
						<?php endif; ?>
						<?php if (isset($consoles[$ai_prov])) : ?>
							<a class="button" href="<?php echo esc_url($consoles[$ai_prov]); ?>" target="_blank" rel="noopener noreferrer">
								<?php Brand::icon('external'); ?>
								<?php esc_html_e('Console', 'lumen-wp'); ?>
							</a>
						<?php endif; ?>
					</p>
				</section>

				<section class="lumen-wp-panel">
					<h2 class="lumen-wp-panel__title"><?php esc_html_e('État serveur', 'lumen-wp'); ?></h2>
					<div class="lumen-wp-dash-caps">
						<span class="<?php echo ! empty($caps['imagick']) ? 'is-ok' : 'is-no'; ?>">Imagick</span>
						<span class="<?php echo ! empty($caps['gd']) ? 'is-ok' : 'is-no'; ?>">GD</span>
						<span class="<?php echo ! empty($caps['webp']) ? 'is-ok' : 'is-no'; ?>">WebP</span>
						<span class="<?php echo ! empty($caps['avif']) ? 'is-ok' : 'is-no'; ?>">AVIF</span>
						<span class="<?php echo ! empty($caps['ghostscript']) ? 'is-ok' : 'is-no'; ?>">Ghostscript</span>
						<span class="<?php echo ! empty($caps['ffmpeg']) ? 'is-ok' : 'is-no'; ?>">FFmpeg</span>
						<span class="<?php echo ! empty($caps['exec']) ? 'is-ok' : 'is-no'; ?>">exec</span>
						<span class="<?php echo ! empty($caps['shell_exec']) ? 'is-ok' : 'is-no'; ?>">shell_exec</span>
						<span class="<?php echo ! empty($caps['openssl']) ? 'is-ok' : 'is-no'; ?>">OpenSSL</span>
						<span class="<?php echo ! empty($caps['action_scheduler']) ? 'is-ok' : 'is-no'; ?>">Action Scheduler</span>
						<span><?php echo esc_html(sprintf(
							/* translators: %s: memory limit */
							__('Mémoire PHP %s', 'lumen-wp'),
							(string) $caps['memory_limit']
						)); ?></span>
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
				</div>
			</div>
			</div>
			</div>
		</div>
		<?php
	}

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
