<?php

declare(strict_types=1);

namespace LumenWp\Admin;

use LumenWp\Bulk_Queue;
use LumenWp\Cleanup;
use LumenWp\Content_Url_Rewriter;
use LumenWp\Reports;

final class Tools
{
	public function register(): void
	{
		add_action('admin_menu', [$this, 'add_menu']);
		add_action('wp_ajax_lumen_wp_cleanup_preview', [$this, 'ajax_cleanup_preview']);
		add_action('wp_ajax_lumen_wp_cleanup_run', [$this, 'ajax_cleanup_run']);
		add_action('wp_ajax_lumen_wp_cron_health', [$this, 'ajax_cron_health']);
		add_action('wp_ajax_lumen_wp_urls_diagnose', [$this, 'ajax_urls_diagnose']);
		add_action('wp_ajax_lumen_wp_urls_rewrite', [$this, 'ajax_urls_rewrite']);
	}

	public function add_menu(): void
	{
		add_submenu_page(
			'lumen-wp',
			__('Outils Lumen', 'lumen-wp'),
			__('Outils', 'lumen-wp'),
			'upload_files',
			'lumen-wp-tools',
			[$this, 'render_page']
		);
	}

	public function render_page(): void
	{
		if (! current_user_can('upload_files')) {
			return;
		}

		$preview = Cleanup::preview();
		$health  = Bulk_Queue::health();

		?>
		<div class="wrap lumen-wp-wrap">
			<?php
			Brand::render_nav('tools');
			Brand::render_header(
				__('Outils', 'lumen-wp'),
				__('Relancer un traitement bloqué, nettoyer les fichiers Lumen, restaurer un original.', 'lumen-wp')
			);

			$status_labels = [
				'idle'    => __('Inactif', 'lumen-wp'),
				'running' => __('En cours', 'lumen-wp'),
				'paused'  => __('En pause', 'lumen-wp'),
				'done'    => __('Terminé', 'lumen-wp'),
			];
			$job_status = (string) ($health['job_status'] ?? 'idle');
			$job_label  = $status_labels[$job_status] ?? $job_status;
			?>

			<section class="lumen-wp-panel" id="lumen-wp-cron-health-panel">
				<h2 class="lumen-wp-panel__title"><?php esc_html_e('Traitement en cours', 'lumen-wp'); ?></h2>
				<p class="description">
					<?php esc_html_e('Si le traitement ne progresse plus, actualisez ou cliquez sur « Avancer maintenant ».', 'lumen-wp'); ?>
				</p>
				<ul class="lumen-wp-tools-health" id="lumen-wp-tools-health">
					<li>
						<span><?php esc_html_e('Statut', 'lumen-wp'); ?></span>
						<strong data-health="job_status"><?php echo esc_html($job_label); ?></strong>
					</li>
					<li>
						<span><?php esc_html_e('Automatique', 'lumen-wp'); ?></span>
						<strong data-health="cron">
							<?php
							echo ! empty($health['cron_disabled'])
								? esc_html__('désactivé', 'lumen-wp')
								: esc_html__('actif', 'lumen-wp');
							?>
						</strong>
					</li>
					<li>
						<span><?php esc_html_e('Prochaine étape', 'lumen-wp'); ?></span>
						<strong data-health="next">
							<?php
							echo ! empty($health['next_scheduled'])
								? esc_html(wp_date('Y-m-d H:i:s', (int) $health['next_scheduled']))
								: '—';
							?>
						</strong>
					</li>
					<li>
						<span><?php esc_html_e('Dernière activité', 'lumen-wp'); ?></span>
						<strong data-health="last">
							<?php
							$last = (string) ($health['last_tick_at'] ?? '');
							echo $last !== '' ? esc_html(wp_date('Y-m-d H:i:s', (int) strtotime($last))) : '—';
							?>
						</strong>
					</li>
					<li>
						<span><?php esc_html_e('Occupé', 'lumen-wp'); ?></span>
						<strong data-health="locked">
							<?php echo ! empty($health['locked']) ? esc_html__('oui', 'lumen-wp') : esc_html__('non', 'lumen-wp'); ?>
						</strong>
					</li>
					<li>
						<span><?php esc_html_e('Bilan', 'lumen-wp'); ?></span>
						<strong data-health="state" class="<?php echo ! empty($health['stale']) || ! empty($health['cron_disabled']) ? 'is-warn' : 'is-ok'; ?>">
							<?php
							if (! empty($health['cron_disabled'])) {
								esc_html_e('Relance manuelle conseillée', 'lumen-wp');
							} elseif (! empty($health['stale'])) {
								esc_html_e('Peut-être bloqué', 'lumen-wp');
							} else {
								esc_html_e('OK', 'lumen-wp');
							}
							?>
						</strong>
					</li>
				</ul>
				<p class="lumen-wp-actions-row">
					<button type="button" class="button" id="lumen-wp-cron-refresh">
						<?php esc_html_e('Actualiser', 'lumen-wp'); ?>
					</button>
					<button type="button" class="button button-primary" id="lumen-wp-cron-force-tick">
						<?php esc_html_e('Avancer maintenant', 'lumen-wp'); ?>
					</button>
				</p>
			</section>

			<section class="lumen-wp-panel">
				<h2 class="lumen-wp-panel__title"><?php esc_html_e('Nettoyage', 'lumen-wp'); ?></h2>
				<p class="description">
					<?php esc_html_e('Supprime les fichiers générés par Lumen (variantes, sauvegardes). Le fichier principal WordPress n’est jamais effacé.', 'lumen-wp'); ?>
				</p>

				<div
					class="lumen-wp-tools-preview"
					id="lumen-wp-cleanup-preview"
					data-label-images="<?php echo esc_attr__('Médias', 'lumen-wp'); ?>"
					data-label-variants="<?php echo esc_attr__('Variantes', 'lumen-wp'); ?>"
					data-label-backups="<?php echo esc_attr__('Sauvegardes', 'lumen-wp'); ?>"
				>
					<article class="lumen-wp-stat">
						<span class="lumen-wp-stat__label"><?php esc_html_e('Médias', 'lumen-wp'); ?></span>
						<strong class="lumen-wp-stat__value" data-preview="attachments"><?php echo esc_html((string) (int) $preview['attachments']); ?></strong>
					</article>
					<article class="lumen-wp-stat">
						<span class="lumen-wp-stat__label"><?php esc_html_e('Variantes', 'lumen-wp'); ?></span>
						<strong class="lumen-wp-stat__value" data-preview="sidecars"><?php echo esc_html((string) (int) $preview['sidecars']); ?></strong>
						<span class="lumen-wp-stat__hint" data-preview="sidecar_bytes"><?php echo esc_html(Cleanup::format_bytes((int) $preview['sidecar_bytes'])); ?></span>
					</article>
					<article class="lumen-wp-stat">
						<span class="lumen-wp-stat__label"><?php esc_html_e('Sauvegardes', 'lumen-wp'); ?></span>
						<strong class="lumen-wp-stat__value" data-preview="backups"><?php echo esc_html((string) (int) $preview['backups']); ?></strong>
						<span class="lumen-wp-stat__hint" data-preview="backup_bytes"><?php echo esc_html(Cleanup::format_bytes((int) $preview['backup_bytes'])); ?></span>
					</article>
				</div>

				<div class="lumen-wp-choices lumen-wp-choices--stack">
					<label class="lumen-wp-choice lumen-wp-choice--wide">
						<input type="checkbox" id="lumen-wp-cleanup-sidecars" value="1" checked />
						<span class="lumen-wp-choice__ui" aria-hidden="true"></span>
						<span class="lumen-wp-choice__label"><?php esc_html_e('Supprimer les variantes Lumen', 'lumen-wp'); ?></span>
					</label>
					<label class="lumen-wp-choice lumen-wp-choice--wide">
						<input type="checkbox" id="lumen-wp-cleanup-backups" value="1" />
						<span class="lumen-wp-choice__ui" aria-hidden="true"></span>
						<span class="lumen-wp-choice__label"><?php esc_html_e('Supprimer les sauvegardes d’originaux', 'lumen-wp'); ?></span>
					</label>
					<label class="lumen-wp-choice lumen-wp-choice--wide">
						<input type="checkbox" id="lumen-wp-cleanup-status" value="1" />
						<span class="lumen-wp-choice__ui" aria-hidden="true"></span>
						<span class="lumen-wp-choice__label"><?php esc_html_e('Remettre les médias « à traiter »', 'lumen-wp'); ?></span>
					</label>
				</div>

				<p class="lumen-wp-actions-row">
					<button type="button" class="button" id="lumen-wp-cleanup-refresh">
						<?php esc_html_e('Recalculer', 'lumen-wp'); ?>
					</button>
					<button type="button" class="button button-primary" id="lumen-wp-cleanup-run">
						<?php esc_html_e('Lancer le nettoyage', 'lumen-wp'); ?>
					</button>
				</p>
				<p class="description" id="lumen-wp-cleanup-result" hidden></p>
			</section>

			<section class="lumen-wp-panel">
				<h2 class="lumen-wp-panel__title"><?php esc_html_e('Restaurer un original', 'lumen-wp'); ?></h2>
				<p class="description">
					<?php esc_html_e('Quand Lumen remplace un fichier image, une copie de l’original est gardée. Pour la récupérer : ouvrez la fiche média → « Restaurer l’original ».', 'lumen-wp'); ?>
				</p>
			</section>

			<section class="lumen-wp-panel">
				<h2 class="lumen-wp-panel__title"><?php esc_html_e('URLs cassées', 'lumen-wp'); ?></h2>
				<p class="description">
					<?php esc_html_e('Après un remplacement (.jpg/.png → .webp), diagnostique les anciennes URLs encore présentes dans les pages, Elementor et les options, puis force une réécriture globale.', 'lumen-wp'); ?>
				</p>
				<p class="lumen-wp-actions-row">
					<button type="button" class="button" id="lumen-wp-urls-diagnose">
						<?php esc_html_e('Diagnostiquer', 'lumen-wp'); ?>
					</button>
					<button type="button" class="button button-primary" id="lumen-wp-urls-rewrite">
						<?php esc_html_e('Réécrire globalement', 'lumen-wp'); ?>
					</button>
				</p>
				<p class="description" id="lumen-wp-urls-summary" hidden></p>
				<div id="lumen-wp-urls-results" class="lumen-wp-urls-results" hidden></div>
			</section>

			<section class="lumen-wp-panel">
				<h2 class="lumen-wp-panel__title"><?php esc_html_e('Rapports', 'lumen-wp'); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %d: max history entries */
						esc_html__('Téléchargez un audit de la médiathèque ou l’historique des traitements (jusqu’à %d runs). Formats : CSV, Excel, PDF.', 'lumen-wp'),
						(int) Bulk_Queue::HISTORY_MAX
					);
					?>
				</p>

				<div class="lumen-wp-reports">
					<div class="lumen-wp-reports__block">
						<h3 class="lumen-wp-reports__label"><?php esc_html_e('Audit médiathèque', 'lumen-wp'); ?></h3>
						<p class="lumen-wp-reports__actions">
							<a class="button" href="<?php echo esc_url(Reports::export_url('audit', 'csv')); ?>">CSV</a>
							<a class="button" href="<?php echo esc_url(Reports::export_url('audit', 'xlsx')); ?>">Excel</a>
							<a class="button" href="<?php echo esc_url(Reports::export_url('audit', 'pdf')); ?>">PDF</a>
						</p>
					</div>
					<div class="lumen-wp-reports__block">
						<h3 class="lumen-wp-reports__label"><?php esc_html_e('Historique des traitements', 'lumen-wp'); ?></h3>
						<p class="lumen-wp-reports__actions">
							<a class="button" href="<?php echo esc_url(Reports::export_url('history', 'csv')); ?>">CSV</a>
							<a class="button" href="<?php echo esc_url(Reports::export_url('history', 'xlsx')); ?>">Excel</a>
							<a class="button" href="<?php echo esc_url(Reports::export_url('history', 'pdf')); ?>">PDF</a>
						</p>
					</div>
				</div>
			</section>
		</div>
		<?php
	}

	public function ajax_cleanup_preview(): void
	{
		$this->guard();
		wp_send_json_success(['preview' => Cleanup::preview()]);
	}

	public function ajax_cleanup_run(): void
	{
		$this->guard();

		$sidecars = ! empty($_POST['sidecars']); // phpcs:ignore WordPress.Security.NonceVerification
		$backups  = ! empty($_POST['backups']); // phpcs:ignore WordPress.Security.NonceVerification
		$status   = ! empty($_POST['clear_status']); // phpcs:ignore WordPress.Security.NonceVerification

		if (! $sidecars && ! $backups && ! $status) {
			wp_send_json_error(['message' => __('Cochez au moins une option.', 'lumen-wp')], 400);
		}

		$result = Cleanup::run(
			[
				'sidecars'     => $sidecars,
				'backups'      => $backups,
				'clear_status' => $status,
			]
		);

		wp_send_json_success(
			[
				'result'  => $result,
				'preview' => Cleanup::preview(),
				'message' => sprintf(
					/* translators: 1: attachments, 2: files deleted, 3: bytes */
					__('Nettoyage terminé — %1$d média(s), %2$d fichier(s) (%3$s).', 'lumen-wp'),
					(int) $result['attachments'],
					(int) $result['deleted'],
					Cleanup::format_bytes((int) $result['bytes'])
				),
			]
		);
	}

	public function ajax_cron_health(): void
	{
		$this->guard();
		$job = Bulk_Queue::job();
		if (($job['status'] ?? '') === 'running' && ! wp_next_scheduled(Bulk_Queue::CRON_HOOK)) {
			wp_clear_scheduled_hook(Bulk_Queue::CRON_HOOK);
			wp_schedule_single_event(time() + 1, Bulk_Queue::CRON_HOOK);
			if (function_exists('spawn_cron')) {
				spawn_cron(time());
			}
		}

		wp_send_json_success(
			[
				'health' => Bulk_Queue::health(),
				'job'    => Bulk_Queue::job(),
			]
		);
	}

	public function ajax_urls_diagnose(): void
	{
		$this->guard();
		@set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$report = Content_Url_Rewriter::diagnose_stale_urls(400);
		wp_send_json_success(['report' => $report]);
	}

	public function ajax_urls_rewrite(): void
	{
		$this->guard();
		@set_time_limit(180); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$result = Content_Url_Rewriter::rewrite_all_stale(400);
		$report = Content_Url_Rewriter::diagnose_stale_urls(400);
		wp_send_json_success(
			[
				'result'  => $result,
				'report'  => $report,
				'message' => sprintf(
					/* translators: 1: attachments, 2: replacements, 3: remaining issues */
					__('Réécriture terminée — %1$d média(s), %2$d remplacement(s). Problèmes restants : %3$d.', 'lumen-wp'),
					(int) $result['attachments'],
					(int) $result['replacements'],
					(int) $result['issues_remaining']
				),
			]
		);
	}

	private function guard(): void
	{
		if (! current_user_can('upload_files')) {
			wp_send_json_error(['message' => __('Permission refusée.', 'lumen-wp')], 403);
		}
		check_ajax_referer('lumen_wp_admin', 'nonce');
	}
}
