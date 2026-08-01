<?php

declare(strict_types=1);

namespace LumenWp;

final class Hooks
{
	/** @var array<int, true> */
	private static array $processing = [];

	/** @var bool */
	private static $skip_auto = false;

	/**
	 * Run a callback without triggering auto_on_upload processing.
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return mixed
	 */
	public static function without_auto_process(callable $callback)
	{
		$prev = self::$skip_auto;
		self::$skip_auto = true;
		try {
			return $callback();
		} finally {
			self::$skip_auto = $prev;
		}
	}

	public function register(): void
	{
		add_filter('wp_generate_attachment_metadata', [$this, 'on_generate_metadata'], 20, 2);
		add_action('admin_notices', [$this, 'capability_notices']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
		// Le plus tôt possible : avant le paint du contenu admin.
		add_action('admin_head', [$this, 'print_critical_admin_css'], 0);
		add_action('admin_print_styles', [$this, 'print_critical_admin_css'], 0);
		// Menu sidebar Lumen — toutes les pages admin.
		add_action('admin_head', [$this, 'print_menu_brand_css'], 20);
		add_action('admin_footer', [$this, 'print_feedback_modal']);
		add_filter('admin_body_class', [$this, 'admin_body_class']);
		add_action('wp_head', [$this, 'print_site_favicons'], 2);
		add_action('delete_attachment', [$this, 'on_delete_attachment'], 10, 1);
	}

	public function on_delete_attachment(int $attachment_id): void
	{
		Cleanup::delete_sidecars($attachment_id);
		Original_Backup::delete($attachment_id, true);
	}

	public function print_feedback_modal(): void
	{
		if (! $this->is_lumen_admin_page() && ! $this->is_media_admin_screen()) {
			return;
		}

		Admin\Brand::render_feedback_modal();
	}

	private function is_media_admin_screen(): bool
	{
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (! $screen) {
			return false;
		}

		return in_array($screen->base, ['upload', 'media', 'post'], true)
			&& ($screen->base !== 'post' || $screen->post_type === 'attachment');
	}

	public function print_site_favicons(): void
	{
		$settings = Plugin::instance()->settings();
		if (! empty($settings['site_favicons'])) {
			// Évite le doublon avec l’icône de site native WP.
			remove_action('wp_head', 'wp_site_icon', 99);
		}
		(new Icon_Kit())->print_head_tags();
	}

	/**
	 * CSS critique avant paint — sans dépendre de body.lumen-wp-admin
	 * (la classe arrive trop tard et provoque un micro-flash blanc).
	 */
	public function print_critical_admin_css(): void
	{
		if (! $this->is_lumen_admin_page()) {
			return;
		}

		static $printed = false;
		if ($printed) {
			return;
		}
		$printed = true;
		?>
		<style id="lumen-wp-critical">
			html.wp-toolbar,
			body.wp-admin {
				background: #0c0a09 !important;
			}
			#wpwrap,
			#wpcontent,
			#wpbody,
			#wpbody-content,
			.wrap {
				background: #0c0a09 !important;
			}
			#wpcontent {
				padding-left: 0 !important;
			}
			#wpfooter {
				display: none !important;
			}
			#wpbody-content > .wrap {
				margin: 0 !important;
			}
		</style>
		<?php
	}

	public function admin_body_class(string $classes): string
	{
		if ($this->is_lumen_admin_page()) {
			$classes .= ' lumen-wp-admin';
		}

		return $classes;
	}

	/**
	 * Teinte magenta Lumen pour l’entrée de menu WP (actif / hover / sous-menu).
	 */
	public function print_menu_brand_css(): void
	{
		?>
		<style id="lumen-wp-menu-brand">
			#adminmenu li#toplevel_page_lumen-wp > a.menu-top {
				border-radius: 0;
			}
			#adminmenu li#toplevel_page_lumen-wp:hover > a.menu-top,
			#adminmenu li#toplevel_page_lumen-wp.opensub > a.menu-top,
			#adminmenu li#toplevel_page_lumen-wp > a.menu-top:focus {
				background: linear-gradient(135deg, rgba(162, 28, 175, 0.92), rgba(232, 121, 249, 0.85)) !important;
				color: #fafaf9 !important;
				box-shadow: none !important;
				border: 0 !important;
			}
			#adminmenu li#toplevel_page_lumen-wp.wp-has-current-submenu > a.wp-has-current-submenu,
			#adminmenu li#toplevel_page_lumen-wp.current > a.menu-top {
				background: linear-gradient(135deg, #a21caf 0%, #c026d3 45%, #e879f9 100%) !important;
				color: #fafaf9 !important;
				font-weight: 600;
				box-shadow: none !important;
				border: 0 !important;
			}
			#adminmenu li#toplevel_page_lumen-wp .wp-menu-name,
			#adminmenu li#toplevel_page_lumen-wp:hover .wp-menu-name,
			#adminmenu li#toplevel_page_lumen-wp.wp-has-current-submenu .wp-menu-name,
			#adminmenu li#toplevel_page_lumen-wp.current .wp-menu-name {
				color: #fafaf9 !important;
			}
			#adminmenu li#toplevel_page_lumen-wp .wp-submenu {
				background: #1c1917 !important;
				border: 0 !important;
				box-shadow: none !important;
			}
			#adminmenu li#toplevel_page_lumen-wp .wp-submenu a {
				color: #a8a29e !important;
			}
			#adminmenu li#toplevel_page_lumen-wp .wp-submenu a:hover,
			#adminmenu li#toplevel_page_lumen-wp .wp-submenu a:focus {
				background: rgba(232, 121, 249, 0.12) !important;
				color: #f0abfc !important;
			}
			#adminmenu li#toplevel_page_lumen-wp .wp-submenu li.current a,
			#adminmenu li#toplevel_page_lumen-wp .wp-submenu li.current a:hover {
				background: rgba(232, 121, 249, 0.18) !important;
				color: #fafaf9 !important;
				font-weight: 600;
			}
			#adminmenu li#toplevel_page_lumen-wp .wp-submenu .wp-submenu-head {
				color: #f0abfc !important;
				background: #1c1917 !important;
			}
			/* Flèche vers le contenu (pages Lumen = fond sombre) */
			body.lumen-wp-admin #adminmenu li#toplevel_page_lumen-wp.wp-has-current-submenu::after,
			body.lumen-wp-admin #adminmenu li#toplevel_page_lumen-wp.current::after {
				border-right-color: #0c0a09 !important;
			}
			#adminmenu #toplevel_page_lumen-wp .wp-menu-image img {
				padding: 5px 0 0 !important;
				opacity: 1 !important;
				width: 22px !important;
				height: 22px !important;
				filter: none !important;
			}
			#adminmenu #toplevel_page_lumen-wp:hover .wp-menu-image img,
			#adminmenu #toplevel_page_lumen-wp.wp-has-current-submenu .wp-menu-image img,
			#adminmenu #toplevel_page_lumen-wp.current .wp-menu-image img {
				opacity: 1 !important;
				filter: none !important;
			}
		</style>
		<?php
	}

	private function is_lumen_admin_page(): bool
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
		if ($page !== '' && (strpos($page, 'lumen-wp') === 0 || $page === 'lumen-wp')) {
			return true;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (! $screen) {
			return false;
		}

		$id = (string) $screen->id;

		return $id === 'toplevel_page_lumen-wp' || strpos($id, 'lumen-wp') !== false;
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	public function on_generate_metadata(array $metadata, int $attachment_id): array
	{
		if (self::$skip_auto) {
			return $metadata;
		}

		$settings = Plugin::instance()->settings();
		if (empty($settings['auto_on_upload'])) {
			return $metadata;
		}

		if (! wp_attachment_is_image($attachment_id)) {
			return $metadata;
		}

		$use_ai = ! empty($settings['auto_seo_on_upload']) && Vision_Ai::is_configured();
		$this->process($attachment_id, false, $use_ai);

		return $metadata;
	}

	/**
	 * Full pipeline for one attachment.
	 *
	 * @return array{ok: bool, status: string, message?: string, rate_limited?: bool}
	 */
	public function process(int $attachment_id, bool $force = false, bool $use_mistral = false): array
	{
		if (isset(self::$processing[$attachment_id])) {
			return [
				'ok'     => false,
				'status' => 'busy',
				'message'=> __('Traitement déjà en cours.', 'lumen-wp'),
			];
		}

		if (! $force && Plugin::attachment_is_done($attachment_id)) {
			return [
				'ok'     => true,
				'status' => 'skipped',
				'message'=> __('Déjà traité.', 'lumen-wp'),
			];
		}

		if (! wp_attachment_is_image($attachment_id)) {
			return [
				'ok'     => false,
				'status' => 'error',
				'message'=> __('Ce média n’est pas une image.', 'lumen-wp'),
			];
		}

		self::$processing[$attachment_id] = true;
		update_post_meta($attachment_id, Plugin::META_STATUS, 'processing');
		delete_post_meta($attachment_id, Plugin::META_ERROR);

		try {
			$optimizer = new Optimizer();
			$result    = $optimizer->process_attachment($attachment_id);
			$variants  = $result['variants'];

			$seo_service = new Seo();
			$settings    = Plugin::instance()->settings();
			$seo         = $seo_service->build_from_filename($attachment_id);
			$rate_limited = false;

			// $use_mistral = demande explicite d’IA (bulk, suggest, upload si auto_seo + clé).
			$want_ai = $use_mistral && Vision_Ai::is_configured();

			if ($want_ai) {
				$ai           = $seo_service->enrich_with_ai($attachment_id, $seo);
				$seo          = $ai['seo'];
				$rate_limited = ! empty($ai['rate_limited']);
			}

			if (! empty($settings['auto_seo_on_upload']) || $use_mistral || $force) {
				$seo_service->apply_to_attachment($attachment_id, $seo, false);
			} else {
				update_post_meta($attachment_id, Plugin::META_SEO, $seo);
			}

			$pack = new Pack();
			$pack->build_and_store($attachment_id, $variants, $seo);

			update_post_meta($attachment_id, Plugin::META_STATUS, 'ok');

			return [
				'ok'           => true,
				'status'       => 'ok',
				'rate_limited' => $rate_limited,
			];
		} catch (\Throwable $e) {
			update_post_meta($attachment_id, Plugin::META_STATUS, 'error');
			update_post_meta($attachment_id, Plugin::META_ERROR, $e->getMessage());

			return [
				'ok'      => false,
				'status'  => 'error',
				'message' => $e->getMessage(),
			];
		} finally {
			unset(self::$processing[$attachment_id]);
		}
	}

	public function capability_notices(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (! $screen || strpos((string) $screen->id, 'lumen') === false) {
			// Also show on plugins/media briefly via settings page mainly.
			if (! $screen || ! in_array($screen->id, ['settings_page_lumen-wp', 'media_page_lumen-wp', 'toplevel_page_lumen-wp'], true)) {
				if (! $screen || $screen->id !== 'upload') {
					return;
				}
			}
		}

		$caps = Plugin::capabilities();
		if (! $caps['imagick'] && ! $caps['gd']) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__('Lumen : Imagick ou GD est requis pour optimiser les images.', 'lumen-wp')
				. '</p></div>';
		}

		$settings = Plugin::instance()->settings();
		$formats  = $settings['formats'] ?? [];
		if (is_array($formats) && in_array('avif', $formats, true) && ! $caps['avif']) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__('Lumen : AVIF est demandé dans les réglages mais non supporté par ce serveur. Les autres formats continueront.', 'lumen-wp')
				. '</p></div>';
		}
	}

	public function enqueue_admin_assets(string $hook): void
	{
		$on_lumen = $this->is_lumen_admin_page() || strpos($hook, 'lumen-wp') !== false;
		$on_media = in_array($hook, ['post.php', 'upload.php', 'media.php'], true)
			|| ($hook === 'post.php' && isset($_GET['post']) && get_post_type((int) $_GET['post']) === 'attachment'); // phpcs:ignore WordPress.Security.NonceVerification

		if (! $on_lumen && ! $on_media) {
			return;
		}

		// Polices non bloquantes (media=print + onload) pour accélérer la nav.
		wp_enqueue_style(
			'lumen-wp-fonts',
			'https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap',
			[],
			null
		);
		wp_style_add_data('lumen-wp-fonts', 'media', 'print');
		add_filter('style_loader_tag', [$this, 'async_font_tag'], 10, 2);

		wp_enqueue_style(
			'lumen-wp-admin',
			LUMEN_WP_URL . 'assets/admin/css/admin.css',
			[],
			LUMEN_WP_VERSION
		);

		wp_enqueue_script(
			'lumen-wp-admin',
			LUMEN_WP_URL . 'assets/admin/js/admin.js',
			['jquery'],
			LUMEN_WP_VERSION,
			true
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings_saved = isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true';

		wp_localize_script(
			'lumen-wp-admin',
			'lumenWp',
			[
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce'   => wp_create_nonce('lumen_wp_admin'),
				'flash'   => $settings_saved
					? [
						'type'    => 'success',
						'title'   => __('Réglages enregistrés', 'lumen-wp'),
						'message' => __('La configuration Lumen a été mise à jour.', 'lumen-wp'),
					]
					: null,
				'i18n'    => [
					'copied'        => __('Copié dans le presse-papiers.', 'lumen-wp'),
					'copyFail'      => __('Impossible de copier.', 'lumen-wp'),
					'processing'    => __('Traitement…', 'lumen-wp'),
					'done'          => __('Terminé.', 'lumen-wp'),
					'error'         => __('Une erreur est survenue.', 'lumen-wp'),
					'successTitle'  => __('Succès', 'lumen-wp'),
					'errorTitle'    => __('Échec', 'lumen-wp'),
					'close'         => __('Fermer', 'lumen-wp'),
					'bulkDone'       => __('Traitement terminé.', 'lumen-wp'),
					'bulkEmpty'      => __('Aucune image à traiter.', 'lumen-wp'),
					'iconsDone'      => __('Kit généré.', 'lumen-wp'),
					'iconsDoneSite'  => __('Kit généré — favicons appliqués au site.', 'lumen-wp'),
					'suggestDone'    => __('Métadonnées suggérées.', 'lumen-wp'),
					'restoreConfirm' => __('Restaurer l’original ? Les variantes Lumen seront supprimées.', 'lumen-wp'),
					'restored'       => __('Original restauré.', 'lumen-wp'),
					'tickForced'     => __('Une image a été traitée.', 'lumen-wp'),
					'cronOk'         => __('Tout va bien. Le traitement avance tout seul en arrière-plan.', 'lumen-wp'),
					'cronDisabled'   => __('Traitement automatique désactivé — utilisez « Avancer maintenant » si besoin.', 'lumen-wp'),
					'cronStale'      => __('Le traitement semble bloqué — cliquez sur « Avancer maintenant ».', 'lumen-wp'),
					'cleanupConfirm' => __('Lancer le nettoyage sélectionné ? Cette action est irréversible.', 'lumen-wp'),
					'statusIdle'     => __('Inactif', 'lumen-wp'),
					'statusRunning'  => __('En cours', 'lumen-wp'),
					'statusPaused'   => __('En pause', 'lumen-wp'),
					'statusDone'     => __('Terminé', 'lumen-wp'),
				],
			]
		);
	}

	/**
	 * @param string $html
	 * @param string $handle
	 */
	public function async_font_tag($html, $handle): string
	{
		if ($handle !== 'lumen-wp-fonts') {
			return $html;
		}

		$html = str_replace("media='print'", "media='print' onload=\"this.media='all'\"", $html);
		$html = str_replace('media="print"', 'media="print" onload="this.media=\'all\'"', $html);

		return $html . '<noscript>' . str_replace(['media=\'print\'', 'media="print"', ' onload="this.media=\'all\'"', ' onload="this.media=\'all\'"'], ['media=\'all\'', 'media="all"', '', ''], $html) . '</noscript>';
	}
}
