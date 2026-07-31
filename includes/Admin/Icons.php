<?php

declare(strict_types=1);

namespace LumenWp\Admin;

use LumenWp\Icon_Kit;
use LumenWp\Plugin;

final class Icons
{
	public function register(): void
	{
		add_action('admin_menu', [$this, 'add_menu']);
		add_action('wp_ajax_lumen_wp_icons_generate', [$this, 'ajax_generate']);
		add_action('wp_ajax_lumen_wp_icons_toggle_site', [$this, 'ajax_toggle_site']);
	}

	public function add_menu(): void
	{
		add_submenu_page(
			'lumen-wp',
			__('Icônes Lumen', 'lumen-wp'),
			__('Icônes', 'lumen-wp'),
			'upload_files',
			'lumen-wp-icons',
			[$this, 'render_page']
		);
	}

	public function render_page(): void
	{
		if (! current_user_can('upload_files')) {
			return;
		}

		$stored   = Icon_Kit::stored();
		$settings = Plugin::instance()->settings();
		$applied  = ! empty($settings['site_favicons']) && ! empty($stored['site']);

		?>
		<div class="wrap lumen-wp-wrap">
			<?php
			Brand::render_nav('icons');
			Brand::render_header(
				__('Kit d’icônes', 'lumen-wp'),
				__('Une image source → plusieurs tailles PNG, ZIP, et favicons du site.', 'lumen-wp')
			);
			?>

			<section class="lumen-wp-panel lumen-wp-icons-panel">
				<input
					type="file"
					id="lumen-wp-icons-file"
					class="lumen-wp-file-input"
					accept=".png,.jpg,.jpeg,.svg,image/png,image/jpeg,image/svg+xml"
				/>
				<div id="lumen-wp-icons-drop" class="lumen-wp-dropzone" tabindex="0" role="button" aria-label="<?php esc_attr_e('Zone de dépôt d’image', 'lumen-wp'); ?>">
					<div class="lumen-wp-dropzone__icon" aria-hidden="true">
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" width="40" height="40">
							<path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>
						</svg>
					</div>
					<p class="lumen-wp-dropzone__title">
						<?php esc_html_e('Glissez-déposez votre image ici ou', 'lumen-wp'); ?>
						<label for="lumen-wp-icons-file" class="lumen-wp-dropzone__browse"><?php esc_html_e('parcourez vos fichiers', 'lumen-wp'); ?></label>
					</p>
					<div class="lumen-wp-dropzone__formats">
						<span>PNG</span><span>JPEG</span><span>SVG</span>
					</div>
				</div>

				<div class="lumen-wp-choices lumen-wp-choices--stack lumen-wp-choices--icons">
					<label class="lumen-wp-choice lumen-wp-choice--wide">
						<input type="checkbox" id="lumen-wp-icons-apply-site" value="1" <?php checked($applied || empty($stored)); ?> />
						<span class="lumen-wp-choice__ui" aria-hidden="true"></span>
						<span class="lumen-wp-choice__label"><?php esc_html_e('Appliquer comme favicons du site (head)', 'lumen-wp'); ?></span>
					</label>
				</div>

				<p class="lumen-wp-actions-row">
					<button type="button" class="button button-primary" id="lumen-wp-icons-generate" disabled>
						<?php esc_html_e('Générer le kit', 'lumen-wp'); ?>
					</button>
					<button type="button" class="button" id="lumen-wp-icons-reset">
						<?php esc_html_e('Réinitialiser', 'lumen-wp'); ?>
					</button>
				</p>

				<p id="lumen-wp-icons-status" class="lumen-wp-icons-status" hidden></p>
			</section>

			<section class="lumen-wp-panel" id="lumen-wp-icons-results" <?php echo empty($stored['kit']) ? 'hidden' : ''; ?>>
				<h2 class="lumen-wp-panel__title"><?php esc_html_e('Résultats', 'lumen-wp'); ?></h2>

				<div id="lumen-wp-icons-grid" class="lumen-wp-icons-grid">
					<?php
					if (! empty($stored['kit']) && is_array($stored['kit'])) {
						foreach ($stored['kit'] as $item) {
							$size = (int) ($item['size'] ?? 0);
							$url  = (string) ($item['url'] ?? '');
							$bytes = (int) ($item['bytes'] ?? 0);
							?>
							<article class="lumen-wp-icon-card">
								<div class="lumen-wp-icon-card__preview">
									<img src="<?php echo esc_url($url); ?>" alt="<?php echo esc_attr($size . 'px'); ?>" width="64" height="64" />
								</div>
								<div class="lumen-wp-icon-card__meta">
									<strong><?php echo esc_html($size . '×' . $size . ' px'); ?></strong>
									<span><?php echo esc_html(size_format($bytes)); ?></span>
								</div>
								<a class="button" href="<?php echo esc_url($url); ?>" download="<?php echo esc_attr('icon-' . $size . '.png'); ?>">
									<?php esc_html_e('PNG', 'lumen-wp'); ?>
								</a>
							</article>
							<?php
						}
					}
					?>
				</div>

				<div class="lumen-wp-icons-site" id="lumen-wp-icons-site">
					<?php if (! empty($stored['site']) && is_array($stored['site'])) : ?>
						<h3 class="lumen-wp-panel__title"><?php esc_html_e('Favicons site', 'lumen-wp'); ?></h3>
						<ul class="lumen-wp-icons-site-list">
							<?php foreach ($stored['site'] as $key => $item) : ?>
								<li>
									<code><?php echo esc_html((string) ($item['filename'] ?? $key)); ?></code>
									—
									<a href="<?php echo esc_url((string) ($item['url'] ?? '#')); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('ouvrir', 'lumen-wp'); ?></a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>

				<p class="lumen-wp-actions-row">
					<?php if (! empty($stored['zip']['url'])) : ?>
						<a class="button button-primary" id="lumen-wp-icons-zip" href="<?php echo esc_url((string) $stored['zip']['url']); ?>">
							<?php esc_html_e('Télécharger tout (ZIP)', 'lumen-wp'); ?>
						</a>
					<?php else : ?>
						<a class="button button-primary" id="lumen-wp-icons-zip" href="#" hidden>
							<?php esc_html_e('Télécharger tout (ZIP)', 'lumen-wp'); ?>
						</a>
					<?php endif; ?>
				</p>
			</section>
		</div>
		<?php
	}

	public function ajax_generate(): void
	{
		$this->guard();

		if (empty($_FILES['file']) || ! is_array($_FILES['file'])) {
			wp_send_json_error(['message' => __('Aucun fichier reçu.', 'lumen-wp')], 400);
		}

		$file = $_FILES['file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if (! empty($file['error'])) {
			wp_send_json_error(['message' => __('Échec upload.', 'lumen-wp')], 400);
		}

		$name = (string) ($file['name'] ?? '');
		$tmp  = (string) ($file['tmp_name'] ?? '');
		$mime = (string) ($file['type'] ?? '');

		if ($tmp === '' || ! is_uploaded_file($tmp)) {
			wp_send_json_error(['message' => __('Upload invalide.', 'lumen-wp')], 400);
		}

		if (! preg_match('/\.(png|jpe?g|svg)$/i', $name) && ! in_array($mime, ['image/png', 'image/jpeg', 'image/jpg', 'image/svg+xml'], true)) {
			wp_send_json_error(['message' => __('Formats acceptés : PNG, JPEG, SVG.', 'lumen-wp')], 400);
		}

		$apply = ! empty($_POST['apply_site']); // phpcs:ignore WordPress.Security.NonceVerification

		try {
			$result = (new Icon_Kit())->generate_from_file($tmp, $mime !== '' ? $mime : 'image/png', $apply);
			wp_send_json_success($result);
		} catch (\Throwable $e) {
			wp_send_json_error(['message' => $e->getMessage()]);
		}
	}

	public function ajax_toggle_site(): void
	{
		$this->guard();

		$enable = ! empty($_POST['enable']); // phpcs:ignore WordPress.Security.NonceVerification
		$stored = Icon_Kit::stored();
		if ($enable && empty($stored['site'])) {
			wp_send_json_error(['message' => __('Générez d’abord un kit.', 'lumen-wp')], 400);
		}

		$settings = Plugin::instance()->settings();
		$settings['site_favicons'] = $enable;
		update_option(Plugin::OPTION_KEY, $settings, false);
		Plugin::instance()->clear_settings_cache();

		if (isset($stored['apply_site'])) {
			$stored['apply_site'] = $enable;
			update_option(Icon_Kit::OPTION_ICONS, $stored, false);
		}

		wp_send_json_success(['enabled' => $enable]);
	}

	private function guard(): void
	{
		if (! current_user_can('upload_files')) {
			wp_send_json_error(['message' => __('Permission refusée.', 'lumen-wp')], 403);
		}
		check_ajax_referer('lumen_wp_admin', 'nonce');
	}
}
