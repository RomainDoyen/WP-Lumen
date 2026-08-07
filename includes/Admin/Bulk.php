<?php

declare(strict_types=1);

namespace LumenWp\Admin;

use LumenWp\Bulk_Queue;
use LumenWp\Media_Types;
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
		$history  = Bulk_Queue::history();
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
				__('Traite les médias en arrière-plan (optimisation, SEO, IA) — continue même si vous fermez l’onglet.', 'lumen-wp')
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
						<th scope="row"><?php esc_html_e('Types de médias', 'lumen-wp'); ?></th>
						<td>
							<div class="lumen-wp-choices lumen-wp-choices--stack" id="lumen-wp-bulk-types">
								<?php foreach (Media_Types::all_types() as $type) : ?>
									<label class="lumen-wp-choice lumen-wp-choice--wide">
										<input type="checkbox" class="lumen-wp-bulk-type" value="<?php echo esc_attr($type); ?>" checked />
										<span class="lumen-wp-choice__ui" aria-hidden="true"></span>
										<span class="lumen-wp-choice__label"><?php echo esc_html(Media_Types::label($type)); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
							<p class="description">
								<?php esc_html_e('Type Images : optimisation + SEO. SVG : SEO seul. PDF / vidéos : SEO + IA (si activée).', 'lumen-wp'); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Traitement', 'lumen-wp'); ?></th>
						<td>
							<div class="lumen-wp-choices lumen-wp-choices--stack">
								<label class="lumen-wp-choice lumen-wp-choice--wide">
									<input type="checkbox" id="lumen-wp-force" value="1" />
									<span class="lumen-wp-choice__ui" aria-hidden="true"></span>
									<span class="lumen-wp-choice__label"><?php esc_html_e('Reprendre aussi les médias déjà OK', 'lumen-wp'); ?></span>
								</label>
								<label
									class="lumen-wp-choice lumen-wp-choice--wide<?php echo Vision_Ai::is_configured() ? '' : ' is-ai-locked'; ?>"
									id="lumen-wp-use-ai-label"
									<?php echo Vision_Ai::is_configured() ? '' : ' data-ai-locked="1"'; ?>
								>
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
									esc_html__('Usage IA ce mois : %1$s / %2$s — type Images, PDF et vidéos (pas les SVG).', 'lumen-wp'),
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

				<div class="lumen-wp-log-shell lumen-wp-errors-shell" id="lumen-wp-bulk-errors-shell" hidden>
					<header class="lumen-wp-log-shell__head">
						<span class="lumen-wp-log-shell__title"><?php esc_html_e('Erreurs', 'lumen-wp'); ?></span>
						<span class="lumen-wp-log-shell__meta" id="lumen-wp-bulk-errors-meta">0</span>
					</header>
					<ul id="lumen-wp-bulk-errors" class="lumen-wp-error-list" aria-live="polite"></ul>
				</div>
			</section>

			<section class="lumen-wp-panel" id="lumen-wp-bulk-history-panel">
				<h2 class="lumen-wp-panel__title"><?php esc_html_e('Historique', 'lumen-wp'); ?></h2>
				<p class="description">
					<?php esc_html_e('10 derniers traitements', 'lumen-wp'); ?>
				</p>
				<ul class="lumen-wp-history" id="lumen-wp-bulk-history" aria-live="polite">
					<?php if ($history === []) : ?>
						<li class="lumen-wp-history__empty" id="lumen-wp-bulk-history-empty">
							<?php esc_html_e('Aucun traitement terminé pour le moment.', 'lumen-wp'); ?>
						</li>
					<?php else : ?>
						<?php foreach ($history as $entry) : ?>
							<?php echo self::render_history_item($entry); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</ul>
			</section>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	public static function render_history_item(array $entry): string
	{
		$started = ! empty($entry['started_at']) ? (int) strtotime((string) $entry['started_at']) : 0;
		$ended   = ! empty($entry['ended_at']) ? (int) strtotime((string) $entry['ended_at']) : 0;
		$when    = $started > 0
			? wp_date('d/m/Y H:i', $started) . ($ended > 0 ? ' → ' . wp_date('H:i', $ended) : '')
			: '—';

		$ended_key = (string) ($entry['ended'] ?? 'stopped');
		$ended_label = $ended_key === 'done'
			? __('Terminé', 'lumen-wp')
			: __('Arrêté', 'lumen-wp');

		$opts = [];
		$types = Media_Types::normalize_types($entry['types'] ?? []);
		if ($types !== []) {
			$opts[] = implode(', ', array_map([Media_Types::class, 'label'], $types));
		}
		if (! empty($entry['force'])) {
			$opts[] = __('Déjà OK repris', 'lumen-wp');
		}
		if (! empty($entry['use_ai'])) {
			$label = (string) ($entry['ai_label'] ?? '');
			$opts[] = $label !== ''
				? sprintf(
					/* translators: %s: AI provider label */
					__('IA (%s)', 'lumen-wp'),
					$label
				)
				: __('IA', 'lumen-wp');
		} else {
			$opts[] = __('Sans IA', 'lumen-wp');
		}

		$user = trim((string) ($entry['user_name'] ?? ''));
		$ok   = (int) ($entry['ok'] ?? 0);
		$err  = (int) ($entry['err'] ?? 0);
		$proc = (int) ($entry['processed'] ?? 0);
		$tot  = (int) ($entry['total_estimate'] ?? 0);
		$errors = is_array($entry['errors'] ?? null) ? $entry['errors'] : [];

		ob_start();
		?>
		<li class="lumen-wp-history__item is-<?php echo esc_attr($ended_key === 'done' ? 'done' : 'stopped'); ?>">
			<div class="lumen-wp-history__top">
				<span class="lumen-wp-history__badge"><?php echo esc_html($ended_label); ?></span>
				<span class="lumen-wp-history__when"><?php echo esc_html($when); ?></span>
				<?php if ($user !== '') : ?>
					<span class="lumen-wp-history__user"><?php echo esc_html($user); ?></span>
				<?php endif; ?>
			</div>
			<p class="lumen-wp-history__stats">
				<?php
				printf(
					/* translators: 1: processed, 2: total estimate, 3: ok, 4: errors */
					esc_html__('%1$d / %2$d traités — %3$d OK · %4$d erreur(s)', 'lumen-wp'),
					$proc,
					$tot,
					$ok,
					$err
				);
				?>
			</p>
			<p class="lumen-wp-history__opts"><?php echo esc_html(implode(' · ', $opts)); ?></p>
			<?php
			$errors = Bulk_Queue::normalize_errors($errors);
			if ($errors !== []) :
				?>
				<ul class="lumen-wp-error-list lumen-wp-error-list--compact">
					<?php foreach ($errors as $err_row) : ?>
						<li class="lumen-wp-error-list__item">
							<?php if ($err_row['edit_url'] !== '') : ?>
								<a class="lumen-wp-error-list__title" href="<?php echo esc_url($err_row['edit_url']); ?>">
									<?php echo esc_html($err_row['title']); ?>
								</a>
							<?php else : ?>
								<span class="lumen-wp-error-list__title"><?php echo esc_html($err_row['title']); ?></span>
							<?php endif; ?>
							<?php if ($err_row['message'] !== '') : ?>
								<span class="lumen-wp-error-list__msg"><?php echo esc_html($err_row['message']); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</li>
		<?php

		return (string) ob_get_clean();
	}
}
