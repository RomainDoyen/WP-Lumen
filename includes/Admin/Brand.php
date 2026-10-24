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
	 * @param 'dashboard'|'bulk'|'history'|'audit'|'tools'|'icons'|'settings' $current
	 */
	public static function render_nav(string $current): void
	{
		$pending = Validation::pending_count();

		$items = [
			'dashboard' => [
				'label' => __('Dashboard', 'lumen-wp'),
				'sub'   => __('Vue d’ensemble', 'lumen-wp'),
				'url'   => admin_url('admin.php?page=lumen-wp'),
				'cap'   => 'upload_files',
			],
			'bulk'      => [
				'label' => __('Traitement', 'lumen-wp'),
				'sub'   => __('File et validation', 'lumen-wp'),
				'url'   => admin_url('admin.php?page=lumen-wp-bulk'),
				'cap'   => 'manage_options',
				'count' => $pending,
			],
			'history'   => [
				'label' => __('Historique', 'lumen-wp'),
				'sub'   => __('Runs passés', 'lumen-wp'),
				'url'   => admin_url('admin.php?page=lumen-wp-history'),
				'cap'   => 'upload_files',
			],
			'audit'     => [
				'label' => __('Audit', 'lumen-wp'),
				'sub'   => __('SEO et GEO', 'lumen-wp'),
				'url'   => admin_url('admin.php?page=lumen-wp-audit'),
				'cap'   => 'manage_options',
			],
			'tools'     => [
				'label' => __('Outils', 'lumen-wp'),
				'sub'   => __('Maintenance, URLs', 'lumen-wp'),
				'url'   => admin_url('admin.php?page=lumen-wp-tools'),
				'cap'   => 'manage_options',
			],
			'icons'     => [
				'label' => __('Icônes', 'lumen-wp'),
				'sub'   => __('Favicon du site', 'lumen-wp'),
				'url'   => admin_url('admin.php?page=lumen-wp-icons'),
				'cap'   => 'upload_files',
			],
			'settings'  => [
				'label' => __('Réglages', 'lumen-wp'),
				'sub'   => __('Apparence, médias, IA', 'lumen-wp'),
				'url'   => admin_url('admin.php?page=lumen-wp-settings'),
				'cap'   => 'manage_options',
			],
		];
		$visible = [];
		foreach ($items as $key => $item) {
			if (! current_user_can($item['cap'])) {
				continue;
			}
			$visible[$key] = $item;
		}
		$keys  = array_keys($visible);
		$index = array_search($current, $keys, true);
		// WordPress déplace les notices après ce marqueur, hors de la fenêtre.
		echo '<hr class="wp-header-end" />';
		?>
		<div class="lumen-wp-shell">
			<nav class="lumen-wp-nav" aria-label="<?php esc_attr_e('Navigation Lumen', 'lumen-wp'); ?>">
				<a class="lumen-wp-nav__logo" href="<?php echo esc_url(admin_url('admin.php?page=lumen-wp')); ?>">
					<img src="<?php echo esc_url(self::logo_url()); ?>" alt="" class="lumen-wp-nav__mark" width="28" height="28" />
					<span class="lumen-wp-nav__name"><?php esc_html_e('Lumen', 'lumen-wp'); ?></span>
					<span class="lumen-wp-nav__version"><?php echo esc_html(LUMEN_WP_VERSION); ?></span>
				</a>
				<ul class="lumen-wp-nav__tabs">
					<li class="lumen-wp-nav__item">
						<span class="lumen-wp-nav__link lumen-wp-nav__link--cap<?php echo $index === 0 ? ' is-top' : ''; ?>" aria-hidden="true"></span>
					</li>
					<?php foreach ($visible as $key => $item) : ?>
						<?php
						$pos = array_search($key, $keys, true);
						$patte = '';
						if ($index !== false && $pos === $index - 1) {
							$patte = ' is-top';
						} elseif ($index !== false && $pos === $index + 1) {
							$patte = ' is-bottom';
						}
						?>
						<li class="lumen-wp-nav__item<?php echo $key === $current ? ' is-active' : ''; ?>">
						<a
							class="lumen-wp-nav__link<?php echo $key === $current ? ' is-active' : ''; ?><?php echo esc_attr($patte); ?>"
							href="<?php echo esc_url($item['url']); ?>"
							<?php echo $key === $current ? 'aria-current="page"' : ''; ?>
						>
							<span class="lumen-wp-nav__copy">
								<span class="lumen-wp-nav__label">
									<?php echo esc_html($item['label']); ?>
									<?php if (! empty($item['count'])) : ?>
										<span class="lumen-wp-nav__count"><?php echo esc_html((string) $item['count']); ?></span>
									<?php endif; ?>
								</span>
								<span class="lumen-wp-nav__sub"><?php echo esc_html($item['sub']); ?></span>
							</span>
							<?php self::icon($key); ?>
						</a>
						</li>
					<?php endforeach; ?>
					<li class="lumen-wp-nav__item">
						<span class="lumen-wp-nav__link lumen-wp-nav__link--cap<?php echo ($index !== false && $index === count($keys) - 1) ? ' is-bottom' : ''; ?>" aria-hidden="true"></span>
					</li>
				</ul>
			</nav>
			<div class="lumen-wp-sheet">
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
					<span class="lumen-wp-modal__icon-info">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" width="28" height="28"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M12 3a9 9 0 100 18 9 9 0 000-18z"/></svg>
					</span>
				</div>
				<h2 id="lumen-wp-modal-title" class="lumen-wp-modal__title"></h2>
				<p id="lumen-wp-modal-message" class="lumen-wp-modal__message"></p>
				<div class="lumen-wp-modal__actions">
					<a id="lumen-wp-modal-action" class="button" hidden href="#"><?php self::icon('external'); ?></a>
					<button type="button" class="button" id="lumen-wp-modal-cancel" data-lumen-modal-close hidden>
						<?php self::icon('close'); ?>
						<?php esc_html_e('Annuler', 'lumen-wp'); ?>
					</button>
					<button type="button" class="button button-primary" id="lumen-wp-modal-ok" data-lumen-modal-close>
						<?php self::icon('validate'); ?>
						<?php esc_html_e('OK', 'lumen-wp'); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Branded page / panel header 
	 */
	public static function render_header(string $title, string $subtitle = '', string $eyebrow = '', string $actions = ''): void
	{
		if ($eyebrow === '') {
			$eyebrow = sprintf(
				/* translators: %s: plugin version number */
				__('Lumen %s', 'lumen-wp'),
				LUMEN_WP_VERSION
			);
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
			<?php if ($actions !== '') : ?>
				<div class="lumen-wp-brand__actions">
					<?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML déjà échappé par l’appelant. ?>
				</div>
			<?php endif; ?>
		</header>
		<?php
	}

	public static function icon(string $name): void
	{
		echo self::render_svg($name, self::icon_paths(), 'lumen-wp-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function scene(string $name): void
	{
		echo self::render_svg($name, self::scene_paths(), 'lumen-wp-scene'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * @param array<string, list<string>> $sets
	 */
	private static function render_svg(string $name, array $sets, string $class): string
	{
		if (! isset($sets[$name])) {
			return '';
		}

		$body = '';
		if ($class === 'lumen-wp-scene') {
			$body .= '<circle class="lumen-wp-scene__plate" cx="12" cy="12" r="10" fill="currentColor" stroke="none"/>';
		}
		foreach ($sets[$name] as $d) {
			$body .= '<path pathLength="1" d="' . esc_attr($d) . '"/>';
		}

		return '<svg class="' . esc_attr($class . ' ' . $class . '--' . $name) . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $body . '</svg>';
	}

	/**
	 * @return array<string, list<string>>
	 */
	private static function icon_paths(): array
	{
		return [
			'dashboard' => [
				'M4 4h6v6H4z',
				'M14 4h6v6h-6z',
				'M4 14h6v6H4z',
				'M14 14h6v6h-6z',
			],
			'bulk' => [
				'M12 3l8 4-8 4-8-4 8-4z',
				'M4 12l8 4 8-4',
				'M4 16l8 4 8-4',
			],
			'history' => [
				'M4 17h16',
				'M8 17V9',
				'M12 17V6',
				'M16 17V12',
			],
			'audit' => [
				'M12 3v3',
				'M12 18v3',
				'M3 12h3',
				'M18 12h3',
				'M12 8a4 4 0 1 0 0.01 0',
			],
			'tools' => [
				'M14 7l3 3-8 8H6v-3l8-8z',
			],
			'icons' => [
				'M4 4h6v6H4z',
				'M14 5l5 3-5 3-5-3 5-3z',
				'M9 15a3 3 0 1 0 0.01 0',
			],
			'settings' => [
				'M4 8h16',
				'M4 16h16',
				'M9 6v4',
				'M15 14v4',
			],
			'validate' => [
				'M5 12l5 5L20 7',
			],
			'export' => [
				'M12 4v10',
				'M8 10l4 4 4-4',
				'M5 19h14',
			],
			'alert' => [
				'M12 9v4',
				'M12 17h.01',
				'M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z',
			],
			'image' => [
				'M4 5h16v14H4z',
				'M4 15l4.5-4.5 3 3L15 10l5 5',
				'M9 9.2h.01',
			],
			'bolt' => [
				'M13 2L4 14h7l-1 8 9-12h-7l1-8z',
			],
			'save' => [
				'M5 4h11l3 3v13H5z',
				'M8 4v5h8V4',
				'M8 20v-6h8v6',
			],
			'refresh' => [
				'M20 12a8 8 0 1 1-2.3-5.6',
				'M20 4v5h-5',
			],
			'forward' => [
				'M5 12h12',
				'M13 7l5 5-5 5',
			],
			'back' => [
				'M19 12H7',
				'M11 7l-5 5 5 5',
			],
			'pause' => [
				'M9 5v14',
				'M15 5v14',
			],
			'play' => [
				'M8 5.5v13l11-6.5-11-6.5z',
			],
			'stop' => [
				'M7 7h10v10H7z',
			],
			'close' => [
				'M6 6l12 12',
				'M18 6L6 18',
			],
			'trash' => [
				'M4 7h16',
				'M9 7V5h6v2',
				'M7 7l1 12h8l1-12',
			],
			'repair' => [
				'M14.7 6.3a4 4 0 0 0-5.6 5.6L4 17v3h3l5.1-5.1a4 4 0 0 0 5.6-5.6L15 12l-3-3 2.7-2.7z',
			],
			'search' => [
				'M17 11c0 3.314-2.686 6-6 6s-6-2.686-6-6 2.686-6 6-6 6 2.686 6 6z',
				'M15.6 15.6L20.4 20.4',
			],
			'external' => [
				'M14 5h5v5',
				'M10 14L19 5',
				'M18 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5',
			],
			'copy' => [
				'M8 8h11v11H8z',
				'M5 16V5h11',
			],
			'restore' => [
				'M4 12a8 8 0 1 0 2.2-5.5',
				'M4 4v5h5',
			],
			'eye' => [
				'M2 12s4-6 10-6 10 6 10 6-4 6-10 6S2 12 2 12z',
				'M12 15a3 3 0 1 0 0.01 0',
			],
			'eye-off' => [
				'M3 3l18 18',
				'M10.5 10.7a3 3 0 0 0 4.2 4.1',
				'M9.9 5.2A11 11 0 0 1 12 5c6 0 10 7 10 7a18 18 0 0 1-3.1 4',
				'M6.1 6.2C3.9 7.9 2 12 2 12s3.2 5.6 8.2 6.7',
			],
			'details' => [
				'M4 6h16',
				'M4 12h16',
				'M4 18h10',
			],
		];
	}

	/**
	 * @return array<string, list<string>>
	 */
	private static function scene_paths(): array
	{
		return [
			'history' => [
				'M7 16.5h10',
				'M7.5 13.5l2.8-3 2.2 2.1 3.8-4.6',
			],
			'bulk' => [
				'M12 5.5l6.2 3.1L12 11.7 5.8 8.6 12 5.5z',
				'M6.2 12.2l5.8 2.9 5.8-2.9',
				'M6.2 15.4l5.8 2.9 5.8-2.9',
			],
			'validate' => [
				'M8.2 12.2l2.5 2.5 5.1-5.4',
			],
			'audit' => [
				'M12 8a4 4 0 1 0 0.01 0',
				'M12 5.2v1.5',
				'M12 17.3v1.5',
				'M5.2 12h1.5',
				'M17.3 12h1.5',
			],
			'icons' => [
				'M6.2 6.4h4v4h-4z',
				'M15.6 6.2l2.3 4H13.3z',
				'M8.4 16.4a1.7 1.7 0 1 0 0.01 0',
			],
		];
	}
}
