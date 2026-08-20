<?php

declare(strict_types=1);

namespace LumenWp\Admin;

use LumenWp\Plugin;
use LumenWp\Seo;

final class Validation
{
	public const PER_PAGE = 20;

	public function register(): void
	{
		add_action('admin_menu', [$this, 'add_menu']);
		add_action('admin_menu', [$this, 'hide_menu'], 999);
		add_action('admin_post_lumen_wp_validation_item', [$this, 'handle_item']);
		add_action('admin_post_lumen_wp_validation_bulk', [$this, 'handle_bulk']);
	}

	public function add_menu(): void
	{
		// Kept for redirects / deep links; hidden from WP sidebar (see hide_menu).
		add_submenu_page(
			'lumen-wp',
			__('Validation IA Lumen', 'lumen-wp'),
			__('Validation', 'lumen-wp'),
			'upload_files',
			'lumen-wp-validation',
			[$this, 'render_page']
		);
	}

	public function hide_menu(): void
	{
		remove_submenu_page('lumen-wp', 'lumen-wp-validation');
	}

	public static function tab_url(array $args = []): string
	{
		return add_query_arg(
			array_merge(
				[
					'page' => 'lumen-wp-bulk',
					'tab'  => 'validate',
				],
				$args
			),
			admin_url('admin.php')
		);
	}

	/**
	 * @return list<int>
	 */
	public static function pending_ids(int $limit = 20, int $offset = 0): array
	{
		global $wpdb;
		$limit  = max(1, min(100, $limit));
		$offset = max(0, $offset);
		$status = Plugin::META_STATUS;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} s
					ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = 'awaiting_validation'
				WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
				ORDER BY p.ID DESC
				LIMIT %d OFFSET %d",
				$status,
				$limit,
				$offset
			)
		);
		// phpcs:enable

		return array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [])));
	}

	public static function pending_count(): int
	{
		global $wpdb;
		$status = Plugin::META_STATUS;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} s
					ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = 'awaiting_validation'
				WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'",
				$status
			)
		);
		// phpcs:enable
	}

	/** Legacy URL → Traitement onglet À valider. */
	public function render_page(): void
	{
		if (! current_user_can('upload_files')) {
			return;
		}

		$args = ['tab' => 'validate'];
		if (isset($_GET['lumen_validated'])) { // phpcs:ignore WordPress.Security.NonceVerification
			$args['lumen_validated'] = sanitize_key((string) wp_unslash($_GET['lumen_validated'])); // phpcs:ignore WordPress.Security.NonceVerification
		}
		if (isset($_GET['paged'])) { // phpcs:ignore WordPress.Security.NonceVerification
			$args['paged'] = max(1, (int) $_GET['paged']); // phpcs:ignore WordPress.Security.NonceVerification
		}

		wp_safe_redirect(self::tab_url($args));
		exit;
	}

	/**
	 * Panel content for Traitement → À valider (no outer wrap/nav).
	 */
	public static function render_tab(): void
	{
		$page   = max(1, (int) ($_GET['paged'] ?? 1)); // phpcs:ignore WordPress.Security.NonceVerification
		$offset = ($page - 1) * self::PER_PAGE;
		$total  = self::pending_count();
		$ids    = self::pending_ids(self::PER_PAGE, $offset);
		$pages  = max(1, (int) ceil($total / self::PER_PAGE));
		$notice = isset($_GET['lumen_validated']) ? sanitize_key((string) wp_unslash($_GET['lumen_validated'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$base   = self::tab_url();

		if ($notice === 'approved') {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Métadonnées approuvées.', 'lumen-wp') . '</p></div>';
		} elseif ($notice === 'rejected') {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Proposition IA rejetée (média optimisé conservé).', 'lumen-wp') . '</p></div>';
		} elseif ($notice === 'bulk') {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Action groupée effectuée.', 'lumen-wp') . '</p></div>';
		}
		?>
		<section class="lumen-wp-panel">
			<p class="description">
				<?php
				printf(
					/* translators: %d: pending count */
					esc_html(_n('%d média en attente', '%d médias en attente', $total, 'lumen-wp')),
					$total
				);
				?>
			</p>

			<?php if ($ids === []) : ?>
				<p><?php esc_html_e('Aucune métadonnée en attente de validation. Lancez un traitement avec IA + validation pour remplir cette file.', 'lumen-wp'); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="lumen-wp-actions-row">
					<?php wp_nonce_field('lumen_wp_validation_bulk'); ?>
					<input type="hidden" name="action" value="lumen_wp_validation_bulk" />
					<input type="hidden" name="ids" value="<?php echo esc_attr(implode(',', $ids)); ?>" />
					<button type="submit" name="bulk_action" value="approve" class="button button-primary">
						<?php esc_html_e('Approuver la page', 'lumen-wp'); ?>
					</button>
					<button type="submit" name="bulk_action" value="reject" class="button">
						<?php esc_html_e('Rejeter la page', 'lumen-wp'); ?>
					</button>
				</form>

				<?php foreach ($ids as $id) : ?>
					<?php self::render_card($id); ?>
				<?php endforeach; ?>

				<?php if ($pages > 1) : ?>
					<p class="lumen-wp-actions-row">
						<?php if ($page > 1) : ?>
							<a class="button" href="<?php echo esc_url(add_query_arg('paged', $page - 1, $base)); ?>"><?php esc_html_e('Précédent', 'lumen-wp'); ?></a>
						<?php endif; ?>
						<span class="description"><?php echo esc_html($page . ' / ' . $pages); ?></span>
						<?php if ($page < $pages) : ?>
							<a class="button" href="<?php echo esc_url(add_query_arg('paged', $page + 1, $base)); ?>"><?php esc_html_e('Suivant', 'lumen-wp'); ?></a>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_card(int $id): void
	{
		$seo   = Seo::get_pending_seo($id);
		$title = get_the_title($id) ?: ('#' . $id);
		$thumb = wp_get_attachment_image($id, 'medium');
		$alt   = (string) ($seo['alt_text'] ?? $seo['alt_text_wcag'] ?? $seo['alt_text_seo'] ?? '');
		?>
		<div class="lumen-wp-panel lumen-wp-validation-card">
			<h2 class="lumen-wp-panel__title"><?php echo esc_html($title); ?> <span class="description">#<?php echo esc_html((string) $id); ?></span></h2>
			<div class="lumen-wp-validation-grid" style="display:grid;grid-template-columns:minmax(140px,220px) 1fr;gap:1.25rem;align-items:start;">
				<div>
					<?php echo $thumb ? wp_kses_post($thumb) : '<p class="description">' . esc_html__('Aperçu indisponible', 'lumen-wp') . '</p>'; ?>
				</div>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<?php wp_nonce_field('lumen_wp_validation_item'); ?>
					<input type="hidden" name="action" value="lumen_wp_validation_item" />
					<input type="hidden" name="attachment_id" value="<?php echo esc_attr((string) $id); ?>" />

					<p>
						<label for="lumen-alt-<?php echo esc_attr((string) $id); ?>"><?php esc_html_e('Texte alternatif', 'lumen-wp'); ?></label><br />
						<input class="large-text" type="text" name="alt" id="lumen-alt-<?php echo esc_attr((string) $id); ?>" value="<?php echo esc_attr($alt); ?>" maxlength="125" />
					</p>
					<p>
						<label for="lumen-title-<?php echo esc_attr((string) $id); ?>"><?php esc_html_e('Titre', 'lumen-wp'); ?></label><br />
						<input class="large-text" type="text" name="title" id="lumen-title-<?php echo esc_attr((string) $id); ?>" value="<?php echo esc_attr((string) ($seo['title'] ?? '')); ?>" />
					</p>
					<p>
						<label for="lumen-caption-<?php echo esc_attr((string) $id); ?>"><?php esc_html_e('Légende', 'lumen-wp'); ?></label><br />
						<textarea class="large-text" name="caption" id="lumen-caption-<?php echo esc_attr((string) $id); ?>" rows="2"><?php echo esc_textarea((string) ($seo['caption'] ?? '')); ?></textarea>
					</p>
					<p>
						<label for="lumen-desc-<?php echo esc_attr((string) $id); ?>"><?php esc_html_e('Description', 'lumen-wp'); ?></label><br />
						<textarea class="large-text" name="description" id="lumen-desc-<?php echo esc_attr((string) $id); ?>" rows="3"><?php echo esc_textarea((string) ($seo['description'] ?? '')); ?></textarea>
					</p>
					<p class="lumen-wp-actions-row">
						<button type="submit" name="item_action" value="approve" class="button button-primary"><?php esc_html_e('Approuver', 'lumen-wp'); ?></button>
						<button type="submit" name="item_action" value="reject" class="button"><?php esc_html_e('Rejeter', 'lumen-wp'); ?></button>
						<a class="button" href="<?php echo esc_url(get_edit_post_link($id, 'raw') ?: '#'); ?>"><?php esc_html_e('Fiche média', 'lumen-wp'); ?></a>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	public function handle_item(): void
	{
		if (! current_user_can('upload_files')) {
			wp_die(esc_html__('Permission refusée.', 'lumen-wp'));
		}
		check_admin_referer('lumen_wp_validation_item');

		$id     = (int) ($_POST['attachment_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification
		$action = sanitize_key((string) ($_POST['item_action'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
		$seo    = new Seo();

		if ($id > 0 && $action === 'approve') {
			$seo->approve_pending(
				$id,
				[
					'alt'         => sanitize_text_field((string) wp_unslash($_POST['alt'] ?? '')), // phpcs:ignore WordPress.Security.NonceVerification
					'title'       => sanitize_text_field((string) wp_unslash($_POST['title'] ?? '')), // phpcs:ignore WordPress.Security.NonceVerification
					'caption'     => sanitize_textarea_field((string) wp_unslash($_POST['caption'] ?? '')), // phpcs:ignore WordPress.Security.NonceVerification
					'description' => sanitize_textarea_field((string) wp_unslash($_POST['description'] ?? '')), // phpcs:ignore WordPress.Security.NonceVerification
				]
			);
			$this->redirect('approved');
		}

		if ($id > 0 && $action === 'reject') {
			$seo->reject_pending($id);
			$this->redirect('rejected');
		}

		$this->redirect('');
	}

	public function handle_bulk(): void
	{
		if (! current_user_can('upload_files')) {
			wp_die(esc_html__('Permission refusée.', 'lumen-wp'));
		}
		check_admin_referer('lumen_wp_validation_bulk');

		$action = sanitize_key((string) ($_POST['bulk_action'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
		$raw    = (string) wp_unslash($_POST['ids'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification
		$ids    = array_values(array_filter(array_map('intval', explode(',', $raw))));
		$seo    = new Seo();

		foreach ($ids as $id) {
			if ($action === 'approve') {
				$seo->approve_pending($id);
			} elseif ($action === 'reject') {
				$seo->reject_pending($id);
			}
		}

		$this->redirect('bulk');
	}

	private function redirect(string $flag): void
	{
		$args = [];
		if ($flag !== '') {
			$args['lumen_validated'] = $flag;
		}
		wp_safe_redirect(self::tab_url($args));
		exit;
	}
}
