<?php

declare(strict_types=1);

namespace LumenWp\Admin;

use LumenWp\Plugin;

final class Settings
{
	public function register(): void
	{
		add_action('admin_menu', [$this, 'add_menu']);
		add_action('admin_init', [$this, 'register_settings']);
	}

	public function add_menu(): void
	{
		add_submenu_page(
			'lumen-wp',
			__('Réglages Lumen', 'lumen-wp'),
			__('Réglages', 'lumen-wp'),
			'manage_options',
			'lumen-wp-settings',
			[$this, 'render_settings_page']
		);
	}

	public function register_settings(): void
	{
		register_setting(
			'lumen_wp_settings_group',
			Plugin::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [$this, 'sanitize'],
				'default'           => Plugin::defaults(),
			]
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string, mixed>
	 */
	public function sanitize($input): array
	{
		$defaults = Plugin::defaults();
		$input    = is_array($input) ? $input : [];

		$formats = [];
		if (! empty($input['formats']) && is_array($input['formats'])) {
			foreach ($input['formats'] as $f) {
				$f = strtolower((string) $f);
				if ($f === 'jpg') {
					$f = 'jpeg';
				}
				if (in_array($f, ['webp', 'avif', 'jpeg'], true)) {
					$formats[] = $f;
				}
			}
		}
		if ($formats === []) {
			$formats = ['webp', 'jpeg'];
		}

		$current = Plugin::instance()->settings();

		$out = [
			'formats'            => array_values(array_unique($formats)),
			'webp_quality'       => $this->clamp_int($input['webp_quality'] ?? $defaults['webp_quality'], 1, 100),
			'jpeg_quality'       => $this->clamp_int($input['jpeg_quality'] ?? $defaults['jpeg_quality'], 1, 100),
			'avif_quality'       => $this->clamp_int($input['avif_quality'] ?? $defaults['avif_quality'], 1, 100),
			'replace_original'   => ! empty($input['replace_original']),
			'auto_on_upload'     => ! empty($input['auto_on_upload']),
			'auto_seo_on_upload' => ! empty($input['auto_seo_on_upload']),
			'mistral_api_key'    => sanitize_text_field((string) ($input['mistral_api_key'] ?? '')),
			'site_url'           => esc_url_raw((string) ($input['site_url'] ?? '')),
			// Géré depuis la page Icônes — ne pas écraser ici.
			'site_favicons'      => ! empty($current['site_favicons']),
		];

		Plugin::instance()->clear_settings_cache();

		return $out;
	}

	private function clamp_int($value, int $min, int $max): int
	{
		return max($min, min($max, (int) $value));
	}

	public function render_settings_page(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		$settings = Plugin::instance()->settings();
		$caps     = Plugin::capabilities();
		$formats  = is_array($settings['formats'] ?? null) ? $settings['formats'] : [];

		?>
		<div class="wrap lumen-wp-wrap">
			<?php
			Brand::render_nav('settings');
			Brand::render_header(
				__('Réglages', 'lumen-wp'),
				__('Optimisation WebP / AVIF et pack SEO pour la médiathèque.', 'lumen-wp')
			);
			?>

			<section class="lumen-wp-panel">
				<h2 class="lumen-wp-panel__title"><?php esc_html_e('Capacités serveur', 'lumen-wp'); ?></h2>
				<table class="widefat striped lumen-wp-caps">
					<thead>
						<tr>
							<th><?php esc_html_e('Capacité', 'lumen-wp'); ?></th>
							<th><?php esc_html_e('Statut', 'lumen-wp'); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td>Imagick</td>
							<td class="<?php echo $caps['imagick'] ? 'lumen-wp-cap-ok' : 'lumen-wp-cap-no'; ?>"><?php echo $caps['imagick'] ? '✓' : '—'; ?></td>
						</tr>
						<tr>
							<td>GD</td>
							<td class="<?php echo $caps['gd'] ? 'lumen-wp-cap-ok' : 'lumen-wp-cap-no'; ?>"><?php echo $caps['gd'] ? '✓' : '—'; ?></td>
						</tr>
						<tr>
							<td>WebP</td>
							<td class="<?php echo $caps['webp'] ? 'lumen-wp-cap-ok' : 'lumen-wp-cap-no'; ?>"><?php echo $caps['webp'] ? '✓' : '—'; ?></td>
						</tr>
						<tr>
							<td>AVIF</td>
							<td class="<?php echo $caps['avif'] ? 'lumen-wp-cap-ok' : 'lumen-wp-cap-no'; ?>"><?php echo $caps['avif'] ? '✓' : '—'; ?></td>
						</tr>
					</tbody>
				</table>
			</section>

			<form method="post" action="options.php" class="lumen-wp-panel">
				<h2 class="lumen-wp-panel__title"><?php esc_html_e('Configuration', 'lumen-wp'); ?></h2>
				<?php settings_fields('lumen_wp_settings_group'); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e('Formats de sortie', 'lumen-wp'); ?></th>
						<td>
							<div class="lumen-wp-choices">
								<label class="lumen-wp-choice">
									<input type="checkbox" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[formats][]" value="webp" <?php checked(in_array('webp', $formats, true)); ?> />
									<span class="lumen-wp-choice__ui" aria-hidden="true"></span>
									<span class="lumen-wp-choice__label">WebP</span>
								</label>
								<label class="lumen-wp-choice">
									<input type="checkbox" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[formats][]" value="avif" <?php checked(in_array('avif', $formats, true)); ?> />
									<span class="lumen-wp-choice__ui" aria-hidden="true"></span>
									<span class="lumen-wp-choice__label">AVIF</span>
								</label>
								<label class="lumen-wp-choice">
									<input type="checkbox" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[formats][]" value="jpeg" <?php checked(in_array('jpeg', $formats, true)); ?> />
									<span class="lumen-wp-choice__ui" aria-hidden="true"></span>
									<span class="lumen-wp-choice__label">JPEG <em><?php esc_html_e('fallback', 'lumen-wp'); ?></em></span>
								</label>
							</div>
							<p class="description"><?php esc_html_e('Défaut recommandé : WebP + JPEG.', 'lumen-wp'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Qualité WebP', 'lumen-wp'); ?></th>
						<td>
							<div class="lumen-wp-quality">
								<input
									type="number"
									min="1"
									max="100"
									step="1"
									inputmode="numeric"
									name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[webp_quality]"
									value="<?php echo esc_attr((string) $settings['webp_quality']); ?>"
									aria-describedby="lumen-wp-quality-help"
								/>
								<span class="lumen-wp-quality__unit" aria-hidden="true">%</span>
							</div>
							<p class="description" id="lumen-wp-quality-help"><?php esc_html_e('Pourcentage de 1 à 100. Plus élevé = meilleure qualité, fichier plus lourd.', 'lumen-wp'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Qualité JPEG', 'lumen-wp'); ?></th>
						<td>
							<div class="lumen-wp-quality">
								<input
									type="number"
									min="1"
									max="100"
									step="1"
									inputmode="numeric"
									name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[jpeg_quality]"
									value="<?php echo esc_attr((string) $settings['jpeg_quality']); ?>"
									aria-describedby="lumen-wp-quality-help-jpeg"
								/>
								<span class="lumen-wp-quality__unit" aria-hidden="true">%</span>
							</div>
							<p class="description" id="lumen-wp-quality-help-jpeg"><?php esc_html_e('Pourcentage de 1 à 100. Plus élevé = meilleure qualité, fichier plus lourd.', 'lumen-wp'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Qualité AVIF', 'lumen-wp'); ?></th>
						<td>
							<div class="lumen-wp-quality">
								<input
									type="number"
									min="1"
									max="100"
									step="1"
									inputmode="numeric"
									name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[avif_quality]"
									value="<?php echo esc_attr((string) $settings['avif_quality']); ?>"
									aria-describedby="lumen-wp-quality-help-avif"
								/>
								<span class="lumen-wp-quality__unit" aria-hidden="true">%</span>
							</div>
							<p class="description" id="lumen-wp-quality-help-avif"><?php esc_html_e('Pourcentage de 1 à 100. AVIF reste souvent plus léger qu’un JPEG à qualité égale.', 'lumen-wp'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Fichier original', 'lumen-wp'); ?></th>
						<td>
							<div class="lumen-wp-choices lumen-wp-choices--stack">
								<label class="lumen-wp-choice lumen-wp-choice--wide">
									<input type="checkbox" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[replace_original]" value="1" <?php checked(! empty($settings['replace_original'])); ?> />
									<span class="lumen-wp-choice__ui" aria-hidden="true"></span>
									<span class="lumen-wp-choice__label"><?php esc_html_e('Remplacer l’original (JPEG/PNG/JPG → WebP prioritaire)', 'lumen-wp'); ?></span>
								</label>
							</div>
							<p class="description"><?php esc_html_e('Sinon : l’original est conservé et les variantes sont écrites à côté (sidecars).', 'lumen-wp'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('À l’upload', 'lumen-wp'); ?></th>
						<td>
							<div class="lumen-wp-choices lumen-wp-choices--stack">
								<label class="lumen-wp-choice lumen-wp-choice--wide">
									<input type="checkbox" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[auto_on_upload]" value="1" <?php checked(! empty($settings['auto_on_upload'])); ?> />
									<span class="lumen-wp-choice__ui" aria-hidden="true"></span>
									<span class="lumen-wp-choice__label"><?php esc_html_e('Optimiser automatiquement les nouvelles images', 'lumen-wp'); ?></span>
								</label>
								<label class="lumen-wp-choice lumen-wp-choice--wide">
									<input type="checkbox" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[auto_seo_on_upload]" value="1" <?php checked(! empty($settings['auto_seo_on_upload'])); ?> />
									<span class="lumen-wp-choice__ui" aria-hidden="true"></span>
									<span class="lumen-wp-choice__label"><?php esc_html_e('Appliquer le SEO automatiquement (règles locales ; IA si clé renseignée)', 'lumen-wp'); ?></span>
								</label>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('URL du site (snippets)', 'lumen-wp'); ?></th>
						<td>
							<input type="url" class="regular-text" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[site_url]" value="<?php echo esc_attr((string) $settings['site_url']); ?>" placeholder="<?php echo esc_attr(home_url()); ?>" />
							<p class="description"><?php esc_html_e('Optionnel. Utilisée pour forcer les URLs absolues dans Gutenberg / JSON-LD.', 'lumen-wp'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Clé API Mistral', 'lumen-wp'); ?></th>
						<td>
							<input type="password" class="regular-text" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[mistral_api_key]" value="<?php echo esc_attr((string) $settings['mistral_api_key']); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e('Optionnel. Active la suggestion d’alts / légendes via Mistral Vision.', 'lumen-wp'); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(__('Enregistrer', 'lumen-wp')); ?>
			</form>
		</div>
		<?php
	}
}
