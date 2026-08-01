<?php

declare(strict_types=1);

namespace LumenWp\Admin;

final class Brand
{
	public static function logo_url(): string
	{
		return LUMEN_WP_URL . 'assets/admin/icons/lumen-mark.svg';
	}

	/**
	 * Data-URI for the WP admin menu icon (sidebar) — version claire.
	 */
	public static function menu_icon(): string
	{
		$path = LUMEN_WP_PATH . 'assets/admin/icons/lumen-mark-menu.svg';
		if (! is_readable($path)) {
			$path = LUMEN_WP_PATH . 'assets/admin/icons/lumen-mark.svg';
		}
		if (! is_readable($path)) {
			return 'dashicons-images-alt2';
		}

		$svg = (string) file_get_contents($path);

		return 'data:image/svg+xml;base64,' . base64_encode($svg);
	}

	/**
	 * In-app navigation (évite la sensation de rechargement WP « nu »).
	 *
	 * @param 'dashboard'|'bulk'|'icons'|'settings' $current
	 */
	public static function render_nav(string $current): void
	{
		$items = [
			'dashboard' => [
				'label' => __('Dashboard', 'lumen-wp'),
				'url'   => admin_url('admin.php?page=lumen-wp'),
				'cap'   => 'upload_files',
			],
			'bulk'      => [
				'label' => __('Traitement', 'lumen-wp'),
				'url'   => admin_url('admin.php?page=lumen-wp-bulk'),
				'cap'   => 'upload_files',
			],
			'tools'     => [
				'label' => __('Outils', 'lumen-wp'),
				'url'   => admin_url('admin.php?page=lumen-wp-tools'),
				'cap'   => 'upload_files',
			],
			'icons'     => [
				'label' => __('Icônes', 'lumen-wp'),
				'url'   => admin_url('admin.php?page=lumen-wp-icons'),
				'cap'   => 'upload_files',
			],
			'settings'  => [
				'label' => __('Réglages', 'lumen-wp'),
				'url'   => admin_url('admin.php?page=lumen-wp-settings'),
				'cap'   => 'manage_options',
			],
		];
		?>
		<nav class="lumen-wp-nav" aria-label="<?php esc_attr_e('Navigation Lumen', 'lumen-wp'); ?>">
			<?php foreach ($items as $key => $item) : ?>
				<?php if (! current_user_can($item['cap'])) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<a
					class="lumen-wp-nav__link<?php echo $key === $current ? ' is-active' : ''; ?>"
					href="<?php echo esc_url($item['url']); ?>"
				><?php echo esc_html($item['label']); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Modale feedback globale (succès / erreur).
	 */
	public static function render_feedback_modal(): void
	{
		?>
		<div id="lumen-wp-modal" class="lumen-wp-modal" hidden aria-hidden="true">
			<div class="lumen-wp-modal__backdrop" data-lumen-modal-close></div>
			<div
				class="lumen-wp-modal__dialog"
				role="alertdialog"
				aria-modal="true"
				aria-labelledby="lumen-wp-modal-title"
				aria-describedby="lumen-wp-modal-message"
			>
				<button type="button" class="lumen-wp-modal__close" data-lumen-modal-close aria-label="<?php esc_attr_e('Fermer', 'lumen-wp'); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
				</button>
				<div class="lumen-wp-modal__icon" aria-hidden="true">
					<span class="lumen-wp-modal__icon-success">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" width="28" height="28"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
					</span>
					<span class="lumen-wp-modal__icon-error">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" width="28" height="28"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
					</span>
				</div>
				<h2 id="lumen-wp-modal-title" class="lumen-wp-modal__title"></h2>
				<p id="lumen-wp-modal-message" class="lumen-wp-modal__message"></p>
				<div class="lumen-wp-modal__actions">
					<button type="button" class="button button-primary" data-lumen-modal-close>
						<?php esc_html_e('OK', 'lumen-wp'); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Branded page / panel header matching Electron desktop shell.
	 */
	public static function render_header(string $title, string $subtitle = '', string $eyebrow = ''): void
	{
		if ($eyebrow === '') {
			$eyebrow = __('Studio image local', 'lumen-wp');
		}
		?>
		<header class="lumen-wp-brand">
			<img
				src="<?php echo esc_url(self::logo_url()); ?>"
				alt=""
				class="lumen-wp-brand__mark"
				width="42"
				height="42"
			/>
			<div class="lumen-wp-brand__text">
				<span class="lumen-wp-brand__eyebrow"><?php echo esc_html($eyebrow); ?></span>
				<h1 class="lumen-wp-brand__title"><?php echo esc_html($title); ?></h1>
				<?php if ($subtitle !== '') : ?>
					<p class="lumen-wp-brand__subtitle"><?php echo esc_html($subtitle); ?></p>
				<?php endif; ?>
			</div>
		</header>
		<?php
	}
}
