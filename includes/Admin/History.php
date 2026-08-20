<?php

declare(strict_types=1);

namespace LumenWp\Admin;

use LumenWp\History_Query;
use LumenWp\Plugin;

final class History
{
	public function register(): void
	{
		add_action('admin_menu', [$this, 'add_menu']);
		add_action('wp_ajax_lumen_wp_history_detail', [$this, 'ajax_detail']);
	}

	public function add_menu(): void
	{
		add_submenu_page(
			'lumen-wp',
			__('Historique Lumen', 'lumen-wp'),
			__('Historique', 'lumen-wp'),
			'upload_files',
			'lumen-wp-history',
			[$this, 'render_page']
		);
	}

	public function render_page(): void
	{
		if (! current_user_can('upload_files')) {
			return;
		}

		$filter = isset($_GET['status']) ? sanitize_key((string) wp_unslash($_GET['status'])) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification
		if (! in_array($filter, History_Query::FILTERS, true)) {
			$filter = 'all';
		}

		$page   = max(1, (int) ($_GET['paged'] ?? 1)); // phpcs:ignore WordPress.Security.NonceVerification
		$counts = History_Query::counts();
		$total  = History_Query::count($filter);
		$pages  = max(1, (int) ceil($total / History_Query::PER_PAGE));
		$page   = min($page, $pages);
		$offset = ($page - 1) * History_Query::PER_PAGE;
		$rows   = History_Query::list($filter, History_Query::PER_PAGE, $offset);
		$base   = admin_url('admin.php?page=lumen-wp-history');
		?>
		<div class="wrap lumen-wp-wrap lumen-wp-theme-<?php echo esc_attr(Plugin::ui_theme()); ?>">
			<?php
			Brand::render_nav('history');
			Brand::render_header(
				__('Historique', 'lumen-wp'),
				__('Médias traités par Lumen — optimisation, SEO et validation.', 'lumen-wp')
			);
			?>

			<nav class="lumen-wp-seg" aria-label="<?php esc_attr_e('Filtrer par statut', 'lumen-wp'); ?>">
				<?php foreach (History_Query::FILTERS as $key) : ?>
					<?php
					$url = $key === 'all' ? $base : add_query_arg('status', $key, $base);
					$n   = (int) ($counts[$key] ?? 0);
					?>
					<a
						class="lumen-wp-seg__item<?php echo $filter === $key ? ' is-active' : ''; ?>"
						href="<?php echo esc_url($url); ?>"
					>
						<span class="lumen-wp-seg__label"><?php echo esc_html(History_Query::filter_label($key)); ?></span>
						<span class="lumen-wp-seg__count"><?php echo esc_html((string) $n); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>

			<section class="lumen-wp-panel lumen-wp-history-panel">
				<?php if ($rows === []) : ?>
					<p class="lumen-wp-history-empty" role="status">
						<?php
						echo esc_html(
							$filter === 'all'
								? __('Aucun média suivi pour l’instant. Lancez un traitement pour remplir l’historique.', 'lumen-wp')
								: sprintf(
									/* translators: %s: filter label */
									__('Aucun média « %s ».', 'lumen-wp'),
									History_Query::filter_label($filter)
								)
						);
						?>
					</p>
				<?php else : ?>
					<ul class="lumen-wp-timeline" id="lumen-wp-history-list">
						<?php foreach ($rows as $row) : ?>
							<li class="lumen-wp-timeline__row" data-status="<?php echo esc_attr((string) $row['status']); ?>">
								<div class="lumen-wp-timeline__media">
									<?php if (! empty($row['thumb_url'])) : ?>
										<img src="<?php echo esc_url((string) $row['thumb_url']); ?>" alt="" width="48" height="48" loading="lazy" />
									<?php else : ?>
										<span class="lumen-wp-timeline__ph" aria-hidden="true"></span>
									<?php endif; ?>
									<div class="lumen-wp-timeline__meta">
										<a class="lumen-wp-timeline__title" href="<?php echo esc_url((string) $row['edit_url']); ?>">
											<?php echo esc_html((string) $row['title']); ?>
										</a>
										<span class="lumen-wp-timeline__sub">
											#<?php echo esc_html((string) $row['id']); ?>
											·
											<?php echo esc_html((string) $row['kind_label']); ?>
										</span>
									</div>
								</div>
								<div class="lumen-wp-timeline__badges">
									<span class="lumen-wp-chip lumen-wp-chip--<?php echo esc_attr(self::chip_mod((string) $row['status'])); ?>">
										<?php echo esc_html((string) $row['status_label']); ?>
									</span>
									<?php if ((string) $row['compression_label'] !== '' && (string) $row['compression_label'] !== '—') : ?>
										<span class="lumen-wp-chip"><?php echo esc_html((string) $row['compression_label']); ?></span>
									<?php endif; ?>
									<?php if ((string) $row['ai_label'] !== '' && (string) $row['ai_label'] !== '—') : ?>
										<span class="lumen-wp-chip lumen-wp-chip--muted"><?php echo esc_html((string) $row['ai_label']); ?></span>
									<?php endif; ?>
								</div>
								<time class="lumen-wp-timeline__date"><?php echo esc_html((string) $row['date']); ?></time>
								<div class="lumen-wp-timeline__actions">
									<button
										type="button"
										class="button lumen-wp-history-detail"
										data-id="<?php echo esc_attr((string) $row['id']); ?>"
									><?php esc_html_e('Détails', 'lumen-wp'); ?></button>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>

					<?php if ($pages > 1) : ?>
						<p class="lumen-wp-actions-row">
							<?php if ($page > 1) : ?>
								<a class="button" href="<?php echo esc_url(add_query_arg(['status' => $filter === 'all' ? false : $filter, 'paged' => $page - 1], $base)); ?>">
									<?php esc_html_e('Précédent', 'lumen-wp'); ?>
								</a>
							<?php endif; ?>
							<span class="description"><?php echo esc_html($page . ' / ' . $pages); ?></span>
							<?php if ($page < $pages) : ?>
								<a class="button" href="<?php echo esc_url(add_query_arg(['status' => $filter === 'all' ? false : $filter, 'paged' => $page + 1], $base)); ?>">
									<?php esc_html_e('Suivant', 'lumen-wp'); ?>
								</a>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</section>

			<div id="lumen-wp-history-modal" class="lumen-wp-modal lumen-wp-history-modal" hidden aria-hidden="true">
				<div class="lumen-wp-modal__backdrop" data-lumen-history-close></div>
				<div
					class="lumen-wp-modal__dialog lumen-wp-history-modal__dialog"
					role="dialog"
					aria-modal="true"
					aria-labelledby="lumen-wp-history-modal-title"
				>
					<button type="button" class="lumen-wp-modal__close" data-lumen-history-close aria-label="<?php esc_attr_e('Fermer', 'lumen-wp'); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
					</button>
					<div class="lumen-wp-history-modal__body" id="lumen-wp-history-modal-body">
						<p class="description"><?php esc_html_e('Chargement…', 'lumen-wp'); ?></p>
					</div>
					<div class="lumen-wp-modal__actions">
						<a id="lumen-wp-history-modal-edit" class="button" href="#" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Fiche média', 'lumen-wp'); ?></a>
						<button type="button" class="button button-primary" data-lumen-history-close><?php esc_html_e('Fermer', 'lumen-wp'); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function ajax_detail(): void
	{
		if (! current_user_can('upload_files')) {
			wp_send_json_error(['message' => __('Permission refusée.', 'lumen-wp')], 403);
		}
		check_ajax_referer('lumen_wp_admin', 'nonce');

		$id = (int) ($_POST['id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification
		if ($id <= 0 || get_post_type($id) !== 'attachment') {
			wp_send_json_error(['message' => __('Média introuvable.', 'lumen-wp')], 404);
		}

		wp_send_json_success(History_Query::detail($id));
	}

	private static function chip_mod(string $status): string
	{
		switch ($status) {
			case 'ok':
				return 'ok';
			case 'error':
				return 'err';
			case 'awaiting_validation':
				return 'warn';
			case 'processing':
				return 'run';
			default:
				return 'muted';
		}
	}
}
