<?php

declare(strict_types=1);

namespace LumenWp\Admin;

use LumenWp\Bulk_Queue;
use LumenWp\Cleanup;
use LumenWp\Content_Url_Rewriter;
use LumenWp\Reports;

final class Tools
{
	private const URLS_RESULT_TRANSIENT = 'lumen_wp_urls_result_';

	public function register(): void
	{
		add_action('admin_menu', [$this, 'add_menu']);
		add_action('wp_ajax_lumen_wp_cleanup_preview', [$this, 'ajax_cleanup_preview']);
		add_action('wp_ajax_lumen_wp_cleanup_run', [$this, 'ajax_cleanup_run']);
		add_action('wp_ajax_lumen_wp_cron_health', [$this, 'ajax_cron_health']);
		// Formulaires admin-post (fiables) — plus d’AJAX pour ces actions.
		add_action('admin_post_lumen_wp_urls_diagnose', [$this, 'handle_urls_diagnose']);
		add_action('admin_post_lumen_wp_urls_rewrite', [$this, 'handle_urls_rewrite']);
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

		$preview     = Cleanup::preview();
		$health      = Bulk_Queue::health();
		$urls_result = $this->consume_urls_result();

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

			<section class="lumen-wp-panel" id="lumen-wp-urls-broken">
				<h2 class="lumen-wp-panel__title"><?php esc_html_e('URLs cassées', 'lumen-wp'); ?></h2>
				<p class="description">
					<?php esc_html_e('Après un remplacement (.jpg/.png → .webp), diagnostique les anciennes URLs encore présentes dans les pages, Elementor et les options, puis force une réécriture globale.', 'lumen-wp'); ?>
				</p>
				<p class="lumen-wp-actions-row">
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="lumen-wp-inline-form">
						<?php wp_nonce_field('lumen_wp_urls'); ?>
						<input type="hidden" name="action" value="lumen_wp_urls_diagnose" />
						<button type="submit" class="button" name="lumen_urls_submit" value="1">
							<?php esc_html_e('Diagnostiquer', 'lumen-wp'); ?>
						</button>
					</form>
					<form
						method="post"
						action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
						class="lumen-wp-inline-form"
						onsubmit="return window.confirm('<?php echo esc_js(__('Réécrire globalement les anciennes URLs (.jpg/.png → .webp) dans le contenu, Elementor et les options ?', 'lumen-wp')); ?>');"
					>
						<?php wp_nonce_field('lumen_wp_urls'); ?>
						<input type="hidden" name="action" value="lumen_wp_urls_rewrite" />
						<button type="submit" class="button button-primary" name="lumen_urls_submit" value="1">
							<?php esc_html_e('Réécrire globalement', 'lumen-wp'); ?>
						</button>
					</form>
				</p>
				<?php $this->render_urls_result($urls_result); ?>
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

	public function handle_urls_diagnose(): void
	{
		$this->guard_post_urls();
		@set_time_limit(180); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		try {
			$report = Content_Url_Rewriter::diagnose_stale_urls(150);
			$this->store_urls_result(
				[
					'ok'      => true,
					'mode'    => 'diagnose',
					'message' => sprintf(
						/* translators: 1: scanned, 2: issues */
						__('Diagnostic terminé — %1$d candidat(s) scanné(s), %2$d URL(s) obsolète(s).', 'lumen-wp'),
						(int) ($report['scanned'] ?? 0),
						(int) ($report['totals']['issues'] ?? 0)
					),
					'report'  => $report,
				]
			);
		} catch (\Throwable $e) {
			$this->store_urls_result(
				[
					'ok'      => false,
					'mode'    => 'diagnose',
					'message' => sprintf(
						/* translators: %s: error */
						__('Diagnostic impossible : %s', 'lumen-wp'),
						$e->getMessage()
					),
					'report'  => null,
				]
			);
		}

		$this->redirect_urls_tools();
	}

	public function handle_urls_rewrite(): void
	{
		$this->guard_post_urls();
		@set_time_limit(240); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		try {
			$result = Content_Url_Rewriter::rewrite_all_stale(150);
			$report = Content_Url_Rewriter::diagnose_stale_urls(100);
			$this->store_urls_result(
				[
					'ok'      => true,
					'mode'    => 'rewrite',
					'message' => sprintf(
						/* translators: 1: attachments, 2: replacements, 3: remaining */
						__('Réécriture terminée — %1$d média(s), %2$d remplacement(s). Problèmes restants : %3$d.', 'lumen-wp'),
						(int) ($result['attachments'] ?? 0),
						(int) ($result['replacements'] ?? 0),
						(int) ($result['issues_remaining'] ?? 0)
					),
					'report'  => $report,
					'result'  => $result,
				]
			);
		} catch (\Throwable $e) {
			$this->store_urls_result(
				[
					'ok'      => false,
					'mode'    => 'rewrite',
					'message' => sprintf(
						/* translators: %s: error */
						__('Réécriture impossible : %s', 'lumen-wp'),
						$e->getMessage()
					),
					'report'  => null,
				]
			);
		}

		$this->redirect_urls_tools();
	}

	/**
	 * @param array<string, mixed>|null $result
	 */
	private function render_urls_result(?array $result): void
	{
		if ($result === null) {
			return;
		}

		$ok      = ! empty($result['ok']);
		$message = (string) ($result['message'] ?? '');
		$report  = is_array($result['report'] ?? null) ? $result['report'] : null;
		$class   = $ok ? 'notice notice-success' : 'notice notice-error';

		if ($message !== '') {
			echo '<div class="' . esc_attr($class) . ' inline"><p>' . esc_html($message) . '</p></div>';
		}

		if (! is_array($report)) {
			return;
		}

		$totals = is_array($report['totals'] ?? null) ? $report['totals'] : [];
		$issues = is_array($report['issues'] ?? null) ? $report['issues'] : [];

		echo '<p class="description">';
		echo esc_html(
			sprintf(
				/* translators: 1: scanned 2: issues 3: posts 4: metas 5: options */
				__('Scan : %1$d — obsolètes : %2$d (posts %3$d, Elementor %4$d, options %5$d).', 'lumen-wp'),
				(int) ($report['scanned'] ?? 0),
				(int) ($totals['issues'] ?? 0),
				(int) ($totals['posts'] ?? 0),
				(int) ($totals['metas'] ?? 0),
				(int) ($totals['options'] ?? 0)
			)
		);
		echo '</p>';

		if ($issues === []) {
			echo '<p class="description">' . esc_html__('Aucune ancienne URL détectée dans le contenu / Elementor / options.', 'lumen-wp') . '</p>';

			return;
		}

		echo '<div class="lumen-wp-urls-results">';
		echo '<table class="widefat striped lumen-wp-urls-table"><thead><tr>';
		echo '<th>' . esc_html__('Média', 'lumen-wp') . '</th>';
		echo '<th>' . esc_html__('Ancienne URL', 'lumen-wp') . '</th>';
		echo '<th>' . esc_html__('Nouvelle URL', 'lumen-wp') . '</th>';
		echo '<th>' . esc_html__('Réfs', 'lumen-wp') . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ($issues as $row) {
			if (! is_array($row)) {
				continue;
			}
			$refs = is_array($row['refs'] ?? null) ? $row['refs'] : [];
			$ref_txt = (int) ($refs['posts'] ?? 0) . 'p / ' . (int) ($refs['metas'] ?? 0) . 'm / ' . (int) ($refs['options'] ?? 0) . 'o';
			$pill = (! empty($row['old_missing']) && ! empty($row['new_exists']))
				? ' <span class="lumen-wp-urls-pill">404→webp</span>'
				: '';

			echo '<tr>';
			echo '<td><strong>' . esc_html((string) ($row['title'] ?? '')) . '</strong> <code>#' . esc_html((string) (int) ($row['id'] ?? 0)) . '</code>' . $pill . '</td>';
			echo '<td class="lumen-wp-urls-url"><code>' . esc_html((string) ($row['old_url'] ?? '')) . '</code></td>';
			echo '<td class="lumen-wp-urls-url"><code>' . esc_html((string) ($row['new_url'] ?? '')) . '</code></td>';
			echo '<td>' . esc_html($ref_txt) . '</td>';
			echo '<td>';
			if (! empty($row['edit_url'])) {
				echo '<a class="button button-small" href="' . esc_url((string) $row['edit_url']) . '">' . esc_html__('Fiche', 'lumen-wp') . '</a>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function store_urls_result(array $payload): void
	{
		$user_id = get_current_user_id();
		set_transient(self::URLS_RESULT_TRANSIENT . $user_id, $payload, 10 * MINUTE_IN_SECONDS);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function consume_urls_result(): ?array
	{
		$user_id = get_current_user_id();
		$key     = self::URLS_RESULT_TRANSIENT . $user_id;
		$raw     = get_transient($key);
		if (! is_array($raw)) {
			return null;
		}
		delete_transient($key);

		return $raw;
	}

	private function redirect_urls_tools(): void
	{
		wp_safe_redirect(admin_url('admin.php?page=lumen-wp-tools#lumen-wp-urls-broken'));
		exit;
	}

	private function guard_post_urls(): void
	{
		if (! current_user_can('upload_files')) {
			wp_die(esc_html__('Permission refusée.', 'lumen-wp'), 403);
		}
		check_admin_referer('lumen_wp_urls');
	}

	private function guard(): void
	{
		if (! current_user_can('upload_files')) {
			wp_send_json_error(['message' => __('Permission refusée.', 'lumen-wp')], 403);
		}
		check_ajax_referer('lumen_wp_admin', 'nonce');
	}
}
