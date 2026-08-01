<?php

declare(strict_types=1);

namespace LumenWp\Admin;

use LumenWp\Bulk_Queue;
use LumenWp\Vision_Ai;

final class Bulk
{
	public function register(): void
	{
		add_action('admin_menu', [$this, 'add_menu']);
	}

	public function add_menu(): void
	{
		add_submenu_page(
			'lumen-wp',
			__('Traitement Lumen', 'lumen-wp'),
			__('Traitement', 'lumen-wp'),
			'upload_files',
			'lumen-wp-bulk',
			[$this, 'render_page']
		);
	}

	public function render_page(): void
	{
		if (! current_user_can('upload_files')) {
			return;
		}

		$job      = Bulk_Queue::job();
		$provider = Vision_Ai::active_provider();
		$usage    = Vision_Ai::usage();
		$budget   = (int) (\LumenWp\Plugin::instance()->settings()['ai_budget_month'] ?? 0);
		$cron_off = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

		?>
		<div class="wrap lumen-wp-wrap">
			<?php
			Brand::render_nav('bulk');
			Brand::render_header(
				__('Traitement', 'lumen-wp'),
				__('Optimise les images en arrière-plan — continue même si vous fermez l’onglet.', 'lumen-wp')
			);
			?>

			<?php if ($cron_off) : ?>
				<p class="lumen-wp-dash-banner lumen-wp-dash-banner--muted">
					<?php esc_html_e('Le traitement automatique WordPress est désactivé sur ce site. Contactez votre hébergeur ou ouvrez Lumen → Outils pour relancer manuellement.', 'lumen-wp'); ?>
				</p>
			<?php endif; ?>

			<section class="lumen-wp-panel lumen-wp-panel--compact" id="lumen-wp-bulk-health">
				<h2 class="lumen-wp-panel__title"><?php esc_html_e('État', 'lumen-wp'); ?></h2>
				<p class="description" id="lumen-wp-bulk-health-text">
					<?php
					$health = Bulk_Queue::health();
					if (! empty($health['stale'])) {
						esc_html_e('Le traitement semble bloqué — cliquez sur « Avancer maintenant ».', 'lumen-wp');
					} elseif ($cron_off) {
						esc_html_e('Traitement automatique désactivé — utilisez « Avancer maintenant » si besoin.', 'lumen-wp');
					} else {
						esc_html_e('Tout va bien. Le traitement avance tout seul en arrière-plan.', 'lumen-wp');
					}
					?>
				</p>
				<p class="lumen-wp-actions-row">
					<button type="button" class="button" id="lumen-wp-bulk-force-tick">
						<?php esc_html_e('Avancer maintenant', 'lumen-wp'); ?>
					</button>
					<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lumen-wp-tools')); ?>">
						<?php esc_html_e('Outils', 'lumen-wp'); ?>
					</a>
				</p>
			</section>

			<section class="lumen-wp-panel">
				<h2 class="lumen-wp-panel__title"><?php esc_html_e('Options', 'lumen-wp'); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e('Traitement', 'lumen-wp'); ?></th>
						<td>
							<div class="lumen-wp-choices lumen-wp-choices--stack">
								<label class="lumen-wp-choice lumen-wp-choice--wide">
									<input type="checkbox" id="lumen-wp-force" value="1" />
									<span class="lumen-wp-choice__ui" aria-hidden="true"></span>
									<span class="lumen-wp-choice__label"><?php esc_html_e('Reprendre aussi les images déjà OK', 'lumen-wp'); ?></span>
								</label>
								<label class="lumen-wp-choice lumen-wp-choice--wide">
									<input type="checkbox" id="lumen-wp-use-ai" value="1" <?php disabled(! Vision_Ai::is_configured()); ?> />
									<span class="lumen-wp-choice__ui" aria-hidden="true"></span>
									<span class="lumen-wp-choice__label">
										<?php
										printf(
											/* translators: %s: provider label */
											esc_html__('Utiliser l’IA Vision (%s)', 'lumen-wp'),
											esc_html(Vision_Ai::provider_label($provider))
										);
										?>
									</span>
								</label>
							</div>
							<p class="description" id="lumen-wp-bulk-ai-meta">
								<?php
								printf(
									/* translators: 1: calls this month, 2: budget or infinity */
									esc_html__('Usage IA ce mois : %1$s / %2$s', 'lumen-wp'),
									esc_html(number_format_i18n((int) $usage['calls_month'])),
									$budget > 0 ? esc_html(number_format_i18n($budget)) : '∞'
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<p class="lumen-wp-actions-row">
					<button type="button" class="button button-primary" id="lumen-wp-bulk-start">
						<?php esc_html_e('Démarrer', 'lumen-wp'); ?>
					</button>
					<button type="button" class="button" id="lumen-wp-bulk-pause" disabled>
						<?php esc_html_e('Pause', 'lumen-wp'); ?>
					</button>
					<button type="button" class="button" id="lumen-wp-bulk-resume" disabled>
						<?php esc_html_e('Reprendre', 'lumen-wp'); ?>
					</button>
					<button type="button" class="button" id="lumen-wp-bulk-stop" disabled>
						<?php esc_html_e('Arrêter', 'lumen-wp'); ?>
					</button>
				</p>

				<div class="lumen-wp-progress" id="lumen-wp-bulk-progress" <?php echo in_array($job['status'], ['running', 'paused', 'done'], true) ? '' : 'hidden'; ?>>
					<progress id="lumen-wp-progress-bar" max="100" value="0"></progress>
					<p id="lumen-wp-progress-label">—</p>
					<p id="lumen-wp-bulk-status-text" class="description"></p>
				</div>

				<div class="lumen-wp-log-shell" id="lumen-wp-bulk-log-shell">
					<header class="lumen-wp-log-shell__head">
						<span class="lumen-wp-log-shell__title"><?php esc_html_e('Activité', 'lumen-wp'); ?></span>
						<span class="lumen-wp-log-shell__meta" id="lumen-wp-bulk-log-meta"><?php esc_html_e('30 derniers messages', 'lumen-wp'); ?></span>
					</header>
					<ul id="lumen-wp-bulk-log" class="lumen-wp-log" aria-live="polite"></ul>
					<p class="lumen-wp-log-empty" id="lumen-wp-bulk-log-empty">
						<?php esc_html_e('Les messages de traitement apparaîtront ici.', 'lumen-wp'); ?>
					</p>
				</div>
			</section>
		</div>
		<?php
	}
}
