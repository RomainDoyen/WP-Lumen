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
	private const AFFECTED_DISPLAY = 8;

	public function register(): void
	{
		add_action('admin_menu', [$this, 'add_menu']);
		add_action('admin_post_lumen_wp_audit_fix', [$this, 'handle_fix']);
		add_action('admin_post_lumen_wp_audit_fix_all', [$this, 'handle_fix_all']);
		add_action('admin_post_lumen_wp_llms_generate', [$this, 'handle_llms_generate']);
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

		if (isset($_GET['export']) && (string) $_GET['export'] === 'audit' && check_admin_referer('lumen_wp_export_audit')) {
			$this->export_csv();
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

		$llms = new Llms_Txt();
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
		?>
		<div class="wrap lumen-wp-wrap lumen-wp-theme-<?php echo esc_attr($theme); ?>">
			<?php Brand::render_nav('audit'); ?>
			<header class="lumen-wp-header">
				<img class="lumen-wp-header__logo" src="<?php echo esc_url(Brand::logo_url()); ?>" alt="" width="40" height="40" />
				<div>
					<h1><?php esc_html_e('Audit SEO & GEO', 'lumen-wp'); ?></h1>
					<p class="lumen-wp-header__sub"><?php esc_html_e('Analyse on-page, médias, signaux GEO et correctifs assistés.', 'lumen-wp'); ?></p>
				</div>
			</header>

			<?php settings_errors('lumen_wp_audit'); ?>

			<div class="lumen-wp-panel">
				<p><?php esc_html_e('Identifie les images, vidéos et pages à corriger. Validez chaque correctif avant application.', 'lumen-wp'); ?></p>
				<form method="post" style="display:inline-block;margin-right:0.75rem;">
					<?php wp_nonce_field('lumen_wp_run_audit'); ?>
					<button type="submit" name="lumen_wp_run_audit" value="1" class="button button-primary"><?php esc_html_e('Lancer l’analyse', 'lumen-wp'); ?></button>
				</form>
				<?php if (is_array($report) && ! empty($report['items'])) : ?>
					<a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=lumen-wp-audit&export=audit'), 'lumen_wp_export_audit')); ?>"><?php esc_html_e('Exporter CSV', 'lumen-wp'); ?></a>
				<?php endif; ?>
			</div>

			<div class="lumen-wp-panel">
				<h2 class="lumen-wp-panel__title"><?php esc_html_e('llms.txt (GEO)', 'lumen-wp'); ?></h2>
				<p class="description">
					<?php
					echo $llms->exists()
						? esc_html(sprintf(
							/* translators: %s: url */
							__('Actif — %s', 'lumen-wp'),
							$llms->public_url()
						))
						: esc_html__('Pas encore généré (ou désactivé dans les réglages).', 'lumen-wp');
					?>
				</p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="lumen_wp_llms_generate" />
					<?php wp_nonce_field('lumen_wp_llms_generate'); ?>
					<button type="submit" class="button" <?php disabled(! $llms->is_enabled()); ?>><?php esc_html_e('Générer / régénérer llms.txt', 'lumen-wp'); ?></button>
					<?php if ($llms->exists()) : ?>
						<a class="button button-link" href="<?php echo esc_url($llms->public_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Ouvrir', 'lumen-wp'); ?></a>
					<?php endif; ?>
				</form>
			</div>

			<?php if (is_array($report)) : ?>
				<div class="lumen-wp-audit-score" role="status">
					<span class="lumen-wp-audit-score__value"><?php echo esc_html((string) ($report['score'] ?? 0)); ?></span>
					<span class="lumen-wp-audit-score__label"><?php esc_html_e('Score SEO/GEO', 'lumen-wp'); ?></span>
					<span class="lumen-wp-audit-score__meta">
						<?php
						printf(
							/* translators: 1: critical 2: warning 3: info 4: date */
							esc_html__('%1$d critiques · %2$d warnings · %3$d infos · %4$s', 'lumen-wp'),
							(int) ($report['summary']['critical'] ?? 0),
							(int) ($report['summary']['warning'] ?? 0),
							(int) ($report['summary']['info'] ?? 0),
							esc_html((string) ($report['generated_at'] ?? ''))
						);
						?>
					</span>
					<?php if (! empty($report['items'])) : ?>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:0.75rem;" onsubmit="return confirm('<?php echo esc_js(__('Appliquer tous les correctifs automatiques du dernier audit ?', 'lumen-wp')); ?>');">
							<input type="hidden" name="action" value="lumen_wp_audit_fix_all" />
							<?php wp_nonce_field('lumen_wp_audit_fix_all'); ?>
							<button type="submit" class="button"><?php esc_html_e('Tout corriger (fixables)', 'lumen-wp'); ?></button>
						</form>
					<?php endif; ?>
				</div>

				<?php foreach ((array) ($report['items'] ?? []) as $item) : ?>
					<?php
					$sev = (string) ($item['severity'] ?? 'info');
					$ids = array_map('intval', (array) ($item['affected_ids'] ?? []));
					?>
					<div class="lumen-wp-panel lumen-wp-audit-item lumen-wp-audit-item--<?php echo esc_attr($sev); ?>">
						<h3 class="lumen-wp-panel__title"><?php echo esc_html((string) ($item['title'] ?? '')); ?></h3>
						<p><?php echo esc_html((string) ($item['description'] ?? '')); ?></p>
						<?php if (! empty($item['action'])) : ?>
							<p class="description"><?php echo esc_html((string) $item['action']); ?></p>
						<?php endif; ?>

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
							<?php if (count((array) $item['affected']) > self::AFFECTED_DISPLAY) : ?>
								<p class="description">
									<?php
									printf(
										/* translators: %d: remaining */
										esc_html__('… et %d autre(s)', 'lumen-wp'),
										count((array) $item['affected']) - self::AFFECTED_DISPLAY
									);
									?>
								</p>
							<?php endif; ?>
						<?php endif; ?>

						<?php if (! empty($item['fixable'])) : ?>
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="lumen-wp-audit-fix" onsubmit="return confirm('<?php echo esc_js((string) ($item['fix_preview'] ?: __('Confirmer ce correctif ?', 'lumen-wp'))); ?>');">
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
				<?php endforeach; ?>

				<?php if (empty($report['items'])) : ?>
					<div class="lumen-wp-panel">
						<p><?php esc_html_e('Aucun problème détecté sur cet échantillon. Bon score !', 'lumen-wp'); ?></p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php Brand::render_feedback_modal(); ?>
		</div>
		<?php
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

		// Refresh audit cache lightly after fix.
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

	private function export_csv(): void
	{
		$report = get_option(Seo_Geo_Auditor::OPTION_LAST);
		if (! is_array($report)) {
			wp_die(esc_html__('Aucun audit à exporter.', 'lumen-wp'));
		}

		$headers = ['severity', 'title', 'description', 'entity_id', 'entity_title', 'issue'];
		$rows    = [];
		foreach ((array) ($report['items'] ?? []) as $item) {
			$affected = (array) ($item['affected'] ?? []);
			if ($affected === []) {
				$rows[] = [
					(string) ($item['severity'] ?? ''),
					(string) ($item['title'] ?? ''),
					(string) ($item['description'] ?? ''),
					'',
					'',
					'',
				];
				continue;
			}
			foreach ($affected as $row) {
				$rows[] = [
					(string) ($item['severity'] ?? ''),
					(string) ($item['title'] ?? ''),
					(string) ($item['description'] ?? ''),
					(string) ($row['id'] ?? ''),
					(string) ($row['title'] ?? ''),
					(string) ($row['issue'] ?? ''),
				];
			}
		}

		Exporters::send_csv('lumen-audit-' . gmdate('Y-m-d') . '.csv', $headers, $rows);
	}
}
