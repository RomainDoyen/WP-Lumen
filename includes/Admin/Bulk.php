<?php

declare(strict_types=1);

namespace LumenWp\Admin;

use LumenWp\Hooks;
use LumenWp\Plugin;

final class Bulk
{
	public function register(): void
	{
		add_action('admin_menu', [$this, 'add_menu']);
		add_action('wp_ajax_lumen_wp_bulk_ids', [$this, 'ajax_ids']);
		add_action('wp_ajax_lumen_wp_bulk_process', [$this, 'ajax_process']);
	}

	public function add_menu(): void
	{
		add_submenu_page(
			'lumen-wp',
			__('Bulk Lumen', 'lumen-wp'),
			__('Bulk', 'lumen-wp'),
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

		?>
		<div class="wrap lumen-wp-wrap">
			<?php
			Brand::render_nav('bulk');
			Brand::render_header(
				__('Bulk', 'lumen-wp'),
				__('Optimise et génère le pack SEO pour les images déjà présentes dans la médiathèque.', 'lumen-wp')
			);
			?>

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
									<span class="lumen-wp-choice__label"><?php esc_html_e('Forcer le re-traitement (y compris déjà OK)', 'lumen-wp'); ?></span>
								</label>
								<label class="lumen-wp-choice lumen-wp-choice--wide">
									<input type="checkbox" id="lumen-wp-use-mistral" value="1" />
									<span class="lumen-wp-choice__ui" aria-hidden="true"></span>
									<span class="lumen-wp-choice__label"><?php esc_html_e('Utiliser Mistral Vision si une clé API est configurée', 'lumen-wp'); ?></span>
								</label>
							</div>
						</td>
					</tr>
				</table>

				<p class="lumen-wp-actions-row">
					<button type="button" class="button button-primary" id="lumen-wp-bulk-start">
						<?php esc_html_e('Démarrer', 'lumen-wp'); ?>
					</button>
					<button type="button" class="button" id="lumen-wp-bulk-stop" disabled>
						<?php esc_html_e('Arrêter', 'lumen-wp'); ?>
					</button>
				</p>

				<div class="lumen-wp-progress" hidden>
					<progress id="lumen-wp-progress-bar" max="100" value="0"></progress>
					<p id="lumen-wp-progress-label">0 / 0</p>
				</div>

				<ul id="lumen-wp-bulk-log" class="lumen-wp-log"></ul>
			</section>
		</div>
		<?php
	}

	public function ajax_ids(): void
	{
		$this->guard();

		$force = ! empty($_POST['force']); // phpcs:ignore WordPress.Security.NonceVerification

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ($force) {
			$ids = $wpdb->get_col(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'attachment'
				  AND post_status = 'inherit'
				  AND post_mime_type LIKE 'image/%'
				ORDER BY ID ASC"
			);
		} else {
			$replace = ! empty(Plugin::instance()->settings()['replace_original']);
			$status  = Plugin::META_STATUS;
			$variants = Plugin::META_VARIANTS;

			if ($replace) {
				// JPEG/PNG encore présents = à traiter (même avec sidecars).
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT p.ID
						FROM {$wpdb->posts} p
						LEFT JOIN {$wpdb->postmeta} s
							ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = 'ok'
						LEFT JOIN {$wpdb->postmeta} v
							ON v.post_id = p.ID AND v.meta_key = %s AND v.meta_value != '' AND v.meta_value != 'a:0:{}'
						WHERE p.post_type = 'attachment'
						  AND p.post_status = 'inherit'
						  AND p.post_mime_type LIKE 'image/%%'
						  AND NOT (
							s.meta_id IS NOT NULL
							AND v.meta_id IS NOT NULL
							AND p.post_mime_type IN ('image/webp', 'image/avif')
						  )
						ORDER BY p.ID ASC",
						$status,
						$variants
					)
				);
			} else {
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT p.ID
						FROM {$wpdb->posts} p
						LEFT JOIN {$wpdb->postmeta} s
							ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = 'ok'
						LEFT JOIN {$wpdb->postmeta} v
							ON v.post_id = p.ID AND v.meta_key = %s AND v.meta_value != '' AND v.meta_value != 'a:0:{}'
						WHERE p.post_type = 'attachment'
						  AND p.post_status = 'inherit'
						  AND p.post_mime_type LIKE 'image/%%'
						  AND (s.meta_id IS NULL OR v.meta_id IS NULL)
						ORDER BY p.ID ASC",
						$status,
						$variants
					)
				);
			}
		}
		// phpcs:enable

		$ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [])));

		wp_send_json_success(['ids' => $ids, 'total' => count($ids)]);
	}

	public function ajax_process(): void
	{
		$this->guard();

		$id = isset($_POST['id']) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$force = ! empty($_POST['force']); // phpcs:ignore WordPress.Security.NonceVerification
		$use_mistral = ! empty($_POST['use_mistral']); // phpcs:ignore WordPress.Security.NonceVerification

		if ($id <= 0) {
			wp_send_json_error(['message' => __('ID invalide.', 'lumen-wp')], 400);
		}

		$result = (new Hooks())->process($id, $force, $use_mistral);

		if (! empty($result['ok'])) {
			wp_send_json_success($result);
		}

		wp_send_json_error($result);
	}

	private function guard(): void
	{
		if (! current_user_can('upload_files')) {
			wp_send_json_error(['message' => __('Permission refusée.', 'lumen-wp')], 403);
		}

		check_ajax_referer('lumen_wp_admin', 'nonce');
	}
}
