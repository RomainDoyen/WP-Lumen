<?php

declare(strict_types=1);

namespace LumenWp\Admin;

use LumenWp\Audit_Fixer;
use LumenWp\Exporters;
use LumenWp\Llms_Txt;
use LumenWp\Plugin;
use LumenWp\Seo_Geo_Auditor;

final class Audit
{
	private const AFFECTED_DISPLAY = 6;

	public function register(): void
	{
		add_action('admin_menu', [$this, 'add_menu']);
		add_action('admin_post_lumen_wp_audit_fix', [$this, 'handle_fix']);
		add_action('admin_post_lumen_wp_audit_fix_all', [$this, 'handle_fix_all']);
		add_action('admin_post_lumen_wp_llms_generate', [$this, 'handle_llms_generate']);
		add_action('admin_post_lumen_wp_export_seo_audit', [$this, 'handle_export_pdf']);
	}

	public function add_menu(): void
	{
		add_submenu_page(
			'lumen-wp',
			__('Audit SEO & GEO Lumen', 'lumen-wp'),
			__('Audit', 'lumen-wp'),
			'manage_options',
			'lumen-wp-audit',
			[$this, 'render_page']
		);
	}

	public function render_page(): void
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Accès refusé.', 'lumen-wp'));
		}

		$report = null;
		if (isset($_POST['lumen_wp_run_audit']) && check_admin_referer('lumen_wp_run_audit')) {
			$report = (new Seo_Geo_Auditor())->run();
			update_option(Seo_Geo_Auditor::OPTION_LAST, $report, false);
			add_settings_error('lumen_wp_audit', 'audit_done', __('Audit SEO/GEO terminé.', 'lumen-wp'), 'success');
		} else {
			$cached = get_option(Seo_Geo_Auditor::OPTION_LAST);
			if (is_array($cached)) {
				$report = $cached;
			}
		}

		$llms  = new Llms_Txt();
		$theme = Plugin::ui_theme();

		$flash = get_transient('lumen_wp_audit_flash_' . get_current_user_id());
		if (is_array($flash)) {
			delete_transient('lumen_wp_audit_flash_' . get_current_user_id());
			add_settings_error(
				'lumen_wp_audit',
				'audit_flash',
				(string) ($flash['message'] ?? ''),
				(($flash['code'] ?? '') === 'updated') ? 'success' : 'error'
			);
		}

		$has_items = is_array($report) && ! empty($report['items']);
		?>
		<div class="wrap lumen-wp-wrap lumen-wp-theme-<?php echo esc_attr($theme); ?>">
			<?php
			Brand::render_nav('audit');
			Brand::render_header(
				__('Audit SEO & GEO', 'lumen-wp'),
				__('Analyse on-page, médias, signaux GEO et correctifs assistés.', 'lumen-wp')
			);
			?>

			<?php settings_errors('lumen_wp_audit'); ?>

			<section class="lumen-wp-audit-top">
				<div class="lumen-wp-panel lumen-wp-audit-run">
					<p class="description"><?php esc_html_e('Identifie les images, vidéos et pages à corriger. Chaque correctif demande confirmation.', 'lumen-wp'); ?></p>
					<div class="lumen-wp-actions-row">
						<form method="post">
							<?php wp_nonce_field('lumen_wp_run_audit'); ?>
							<button type="submit" name="lumen_wp_run_audit" value="1" class="button button-primary"><?php esc_html_e('Lancer l’analyse', 'lumen-wp'); ?></button>
						</form>
						<?php if (is_array($report)) : ?>
							<a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lumen_wp_export_seo_audit'), 'lumen_wp_export_seo_audit')); ?>"><?php esc_html_e('Exporter PDF', 'lumen-wp'); ?></a>
						<?php endif; ?>
					</div>
				</div>

				<div class="lumen-wp-panel lumen-wp-audit-llms">
					<h2 class="lumen-wp-panel__title"><?php esc_html_e('llms.txt', 'lumen-wp'); ?></h2>
					<p class="description lumen-wp-audit-llms__status">
						<?php if ($llms->exists()) : ?>
							<?php
							echo wp_kses(
								sprintf(
									/* translators: %s: public url */
									__('Actif — %s', 'lumen-wp'),
									'<a href="' . esc_url($llms->public_url()) . '" target="_blank" rel="noopener noreferrer">' . esc_html($llms->public_url()) . '</a>'
								),
								[
									'a' => [
										'href'   => true,
										'target' => true,
										'rel'    => true,
									],
								]
							);
							?>
						<?php else : ?>
							<?php esc_html_e('Pas encore généré (ou désactivé).', 'lumen-wp'); ?>
						<?php endif; ?>
					</p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="lumen-wp-actions-row">
						<input type="hidden" name="action" value="lumen_wp_llms_generate" />
						<?php wp_nonce_field('lumen_wp_llms_generate'); ?>
						<button type="submit" class="button" <?php disabled(! $llms->is_enabled()); ?>><?php esc_html_e('Générer', 'lumen-wp'); ?></button>
						<?php if ($llms->exists()) : ?>
							<a class="button" href="<?php echo esc_url($llms->public_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Ouvrir', 'lumen-wp'); ?></a>
						<?php endif; ?>
					</form>
				</div>
			</section>

			<?php if (is_array($report)) : ?>
				<?php
				$score = max(0, min(100, (int) ($report['score'] ?? 0)));
				$circ  = 2 * M_PI * 15.5;
				$offset = $circ * (1 - ($score / 100));
				?>
				<section class="lumen-wp-audit-scorebar" role="status">
					<div
						class="lumen-wp-audit-ring"
						role="img"
						aria-label="<?php echo esc_attr(sprintf(/* translators: %d: score percent */ __('Score d’audit : %d pour cent', 'lumen-wp'), $score)); ?>"
					>
						<svg class="lumen-wp-audit-ring__svg" viewBox="0 0 36 36" aria-hidden="true" focusable="false">
							<circle class="lumen-wp-audit-ring__track" cx="18" cy="18" r="15.5" />
							<circle
								class="lumen-wp-audit-ring__progress"
								cx="18"
								cy="18"
								r="15.5"
								stroke-dasharray="<?php echo esc_attr((string) round($circ, 2)); ?>"
								stroke-dashoffset="<?php echo esc_attr((string) round($offset, 2)); ?>"
							/>
						</svg>
						<span class="lumen-wp-audit-ring__value">
							<?php echo esc_html((string) $score); ?><span class="lumen-wp-audit-ring__unit">%</span>
						</span>
					</div>
					<div class="lumen-wp-audit-scorebar__stats">
						<span><strong><?php echo esc_html((string) (int) ($report['summary']['critical'] ?? 0)); ?></strong> <?php esc_html_e('critiques', 'lumen-wp'); ?></span>
						<span><strong><?php echo esc_html((string) (int) ($report['summary']['warning'] ?? 0)); ?></strong> <?php esc_html_e('à améliorer', 'lumen-wp'); ?></span>
						<span><strong><?php echo esc_html((string) (int) ($report['summary']['info'] ?? 0)); ?></strong> <?php esc_html_e('infos', 'lumen-wp'); ?></span>
						<span class="lumen-wp-audit-scorebar__when"><?php echo esc_html((string) ($report['generated_at'] ?? '')); ?></span>
					</div>
					<?php if ($has_items) : ?>
						<form
							method="post"
							action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
							class="lumen-wp-audit-scorebar__fix"
							data-lumen-confirm="<?php echo esc_attr(__('Appliquer tous les correctifs automatiques du dernier audit ?', 'lumen-wp')); ?>"
							data-lumen-confirm-title="<?php echo esc_attr__('Tout corriger', 'lumen-wp'); ?>"
						>
							<input type="hidden" name="action" value="lumen_wp_audit_fix_all" />
							<?php wp_nonce_field('lumen_wp_audit_fix_all'); ?>
							<button type="submit" class="button"><?php esc_html_e('Tout corriger', 'lumen-wp'); ?></button>
						</form>
					<?php endif; ?>
				</section>

				<?php if ($has_items) : ?>
					<section class="lumen-wp-panel lumen-wp-audit-list-panel">
						<h2 class="lumen-wp-panel__title"><?php esc_html_e('Points à corriger', 'lumen-wp'); ?></h2>
						<ul class="lumen-wp-audit-list">
							<?php foreach ((array) $report['items'] as $item) : ?>
								<?php
								$sev     = (string) ($item['severity'] ?? 'info');
								$ids     = array_map('intval', (array) ($item['affected_ids'] ?? []));
								$count   = count((array) ($item['affected'] ?? []));
								$preview = (string) ($item['fix_preview'] ?: __('Confirmer ce correctif ?', 'lumen-wp'));
								?>
								<li class="lumen-wp-audit-row lumen-wp-audit-row--<?php echo esc_attr($sev); ?>">
									<header class="lumen-wp-audit-row__head">
										<span class="lumen-wp-chip lumen-wp-chip--<?php echo esc_attr($this->sev_chip($sev)); ?>">
											<?php echo esc_html($this->sev_label($sev)); ?>
										</span>
										<strong class="lumen-wp-audit-row__title"><?php echo esc_html((string) ($item['title'] ?? '')); ?></strong>
										<div class="lumen-wp-audit-row__actions">
											<?php if (! empty($item['fixable'])) : ?>
												<form
													method="post"
													action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
													data-lumen-confirm="<?php echo esc_attr($preview); ?>"
													data-lumen-confirm-title="<?php echo esc_attr((string) ($item['title'] ?? __('Corriger', 'lumen-wp'))); ?>"
												>
													<input type="hidden" name="action" value="lumen_wp_audit_fix" />
													<input type="hidden" name="issue_id" value="<?php echo esc_attr((string) ($item['id'] ?? '')); ?>" />
													<input type="hidden" name="entity_ids" value="<?php echo esc_attr(implode(',', $ids)); ?>" />
													<?php wp_nonce_field('lumen_wp_audit_fix'); ?>
													<button type="submit" class="button button-primary"><?php esc_html_e('Corriger', 'lumen-wp'); ?></button>
												</form>
											<?php elseif (! empty($item['link'])) : ?>
												<a class="button" href="<?php echo esc_url((string) $item['link']); ?>"><?php esc_html_e('Ouvrir', 'lumen-wp'); ?></a>
											<?php endif; ?>
										</div>
									</header>
									<p class="lumen-wp-audit-row__desc"><?php echo esc_html((string) ($item['description'] ?? '')); ?></p>
									<?php if (! empty($item['affected'])) : ?>
										<ul class="lumen-wp-audit-affected">
											<?php
											$shown = 0;
											foreach ((array) $item['affected'] as $row) :
												if ($shown >= self::AFFECTED_DISPLAY) {
													break;
												}
												++$shown;
												$url = (string) ($row['edit_url'] ?? '');
												?>
												<li>
													<?php if ($url !== '') : ?>
														<a href="<?php echo esc_url($url); ?>"><?php echo esc_html((string) ($row['title'] ?? '#')); ?></a>
													<?php else : ?>
														<?php echo esc_html((string) ($row['title'] ?? '#')); ?>
													<?php endif; ?>
													<?php if (! empty($row['issue'])) : ?>
														<span class="description"> — <?php echo esc_html((string) $row['issue']); ?></span>
													<?php endif; ?>
												</li>
											<?php endforeach; ?>
										</ul>
										<?php if ($count > self::AFFECTED_DISPLAY) : ?>
											<p class="description lumen-wp-audit-row__more">
												<?php
												printf(
													/* translators: %d: remaining */
													esc_html__('… et %d autre(s)', 'lumen-wp'),
													$count - self::AFFECTED_DISPLAY
												);
												?>
											</p>
										<?php endif; ?>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php else : ?>
					<section class="lumen-wp-panel">
						<p><?php esc_html_e('Aucun problème détecté sur cet échantillon. Bon score !', 'lumen-wp'); ?></p>
					</section>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function sev_chip(string $sev): string
	{
		switch ($sev) {
			case 'critical':
				return 'err';
			case 'warning':
				return 'warn';
			default:
				return 'run';
		}
	}

	private function sev_label(string $sev): string
	{
		switch ($sev) {
			case 'critical':
				return __('Critique', 'lumen-wp');
			case 'warning':
				return __('À améliorer', 'lumen-wp');
			default:
				return __('Info', 'lumen-wp');
		}
	}

	public function handle_fix(): void
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Accès refusé.', 'lumen-wp'));
		}
		check_admin_referer('lumen_wp_audit_fix');

		$issue = sanitize_key((string) ($_POST['issue_id'] ?? ''));
		$raw   = sanitize_text_field((string) ($_POST['entity_ids'] ?? ''));
		$ids   = array_filter(array_map('intval', $raw !== '' ? explode(',', $raw) : []));

		$result = (new Audit_Fixer())->fix($issue, $ids);
		$msg    = (string) ($result['message'] ?? '');
		$code   = ! empty($result['success']) ? 'updated' : 'error';
		set_transient(
			'lumen_wp_audit_flash_' . get_current_user_id(),
			['code' => $code, 'message' => $msg],
			60
		);

		$report = (new Seo_Geo_Auditor())->run();
		update_option(Seo_Geo_Auditor::OPTION_LAST, $report, false);

		wp_safe_redirect(admin_url('admin.php?page=lumen-wp-audit&fixed=1'));
		exit;
	}

	public function handle_fix_all(): void
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Accès refusé.', 'lumen-wp'));
		}
		check_admin_referer('lumen_wp_audit_fix_all');

		$result = (new Audit_Fixer())->fix_all();
		set_transient(
			'lumen_wp_audit_flash_' . get_current_user_id(),
			[
				'code'    => ! empty($result['success']) ? 'updated' : 'error',
				'message' => (string) ($result['message'] ?? ''),
			],
			60
		);

		$report = (new Seo_Geo_Auditor())->run();
		update_option(Seo_Geo_Auditor::OPTION_LAST, $report, false);

		wp_safe_redirect(admin_url('admin.php?page=lumen-wp-audit&fixed=1'));
		exit;
	}

	public function handle_llms_generate(): void
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Accès refusé.', 'lumen-wp'));
		}
		check_admin_referer('lumen_wp_llms_generate');

		$result = (new Llms_Txt())->generate();
		set_transient(
			'lumen_wp_audit_flash_' . get_current_user_id(),
			[
				'code'    => ! empty($result['success']) ? 'updated' : 'error',
				'message' => (string) ($result['message'] ?? ''),
			],
			60
		);

		wp_safe_redirect(admin_url('admin.php?page=lumen-wp-audit'));
		exit;
	}

	public function handle_export_pdf(): void
	{
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Accès refusé.', 'lumen-wp'));
		}
		check_admin_referer('lumen_wp_export_seo_audit');

		$report = get_option(Seo_Geo_Auditor::OPTION_LAST);
		if (! is_array($report)) {
			wp_die(esc_html__('Aucun audit à exporter.', 'lumen-wp'));
		}

		$score   = max(0, min(100, (int) ($report['score'] ?? 0)));
		$summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
		$stamp   = wp_date('Y-m-d_His');
		$site    = wp_parse_url(home_url('/'), PHP_URL_HOST);
		$site    = is_string($site) && $site !== '' ? $site : home_url('/');

		$sev_labels = [
			'critical' => __('Critique', 'lumen-wp'),
			'warning'  => __('À améliorer', 'lumen-wp'),
			'info'     => __('Info', 'lumen-wp'),
		];

		$table_rows = [];
		$list_items = [];
		foreach ((array) ($report['items'] ?? []) as $item) {
			$sev   = (string) ($item['severity'] ?? 'info');
			$title = (string) ($item['title'] ?? '');
			$desc  = (string) ($item['description'] ?? '');
			$count = count((array) ($item['affected'] ?? []));
			if ($count === 0 && ! empty($item['affected_ids']) && is_array($item['affected_ids'])) {
				$count = count($item['affected_ids']);
			}

			$table_rows[] = [
				$sev_labels[$sev] ?? $sev,
				$title,
				(string) $count,
			];
			$list_items[] = '[' . ($sev_labels[$sev] ?? $sev) . '] ' . $title
				. ($desc !== '' ? ' — ' . $desc : '')
				. ($count > 0 ? ' (' . $count . ')' : '');
		}

		Exporters::send_pdf(
			'lumen-audit-seo-geo-' . $stamp . '.pdf',
			[
				'title'    => __('Audit SEO & GEO', 'lumen-wp'),
				'subtitle' => (string) $site,
				'meta'     => [
					['label' => __('Généré le', 'lumen-wp'), 'value' => wp_date('d/m/Y H:i')],
					['label' => __('Version', 'lumen-wp'), 'value' => LUMEN_WP_VERSION],
					['label' => __('Points listés', 'lumen-wp'), 'value' => (string) count($table_rows)],
				],
				'kpis'     => [
					['label' => __('Score', 'lumen-wp'), 'value' => $score . '%', 'tone' => $score >= 80 ? 'ok' : ($score >= 50 ? 'warn' : 'error')],
					['label' => __('Critiques', 'lumen-wp'), 'value' => (string) (int) ($summary['critical'] ?? 0), 'tone' => 'error'],
					['label' => __('À améliorer', 'lumen-wp'), 'value' => (string) (int) ($summary['warning'] ?? 0), 'tone' => 'warn'],
					['label' => __('Infos', 'lumen-wp'), 'value' => (string) (int) ($summary['info'] ?? 0), 'tone' => 'neutral'],
				],
				'sections' => [
					[
						'title'   => __('Synthèse des points', 'lumen-wp'),
						'type'    => 'table',
						'headers' => [
							__('Sévérité', 'lumen-wp'),
							__('Titre', 'lumen-wp'),
							__('Éléments', 'lumen-wp'),
						],
						'rows'    => $table_rows,
					],
					[
						'title' => __('Détail', 'lumen-wp'),
						'type'  => 'list',
						'items' => $list_items,
					],
				],
			]
		);
	}
}
