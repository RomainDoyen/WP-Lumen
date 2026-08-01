<?php

declare(strict_types=1);

namespace LumenWp\Admin;

use LumenWp\Plugin;
use LumenWp\Vision_Ai;

final class Settings
{
	public function register(): void
	{
		add_action('admin_menu', [$this, 'add_menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('wp_ajax_lumen_wp_ai_usage_reset', [$this, 'ajax_reset_usage']);
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

		$current  = Plugin::instance()->settings();
		$provider = strtolower(sanitize_key((string) ($input['ai_provider'] ?? 'none')));
		if (! in_array($provider, Vision_Ai::PROVIDERS, true)) {
			$provider = 'none';
		}

		$out = [
			'formats'            => array_values(array_unique($formats)),
			'webp_quality'       => $this->clamp_int($input['webp_quality'] ?? $defaults['webp_quality'], 1, 100),
			'jpeg_quality'       => $this->clamp_int($input['jpeg_quality'] ?? $defaults['jpeg_quality'], 1, 100),
			'avif_quality'       => $this->clamp_int($input['avif_quality'] ?? $defaults['avif_quality'], 1, 100),
			'replace_original'   => ! empty($input['replace_original']),
			'auto_on_upload'     => ! empty($input['auto_on_upload']),
			'auto_seo_on_upload' => ! empty($input['auto_seo_on_upload']),
			'ai_provider'        => $provider,
			'mistral_api_key'    => sanitize_text_field((string) ($input['mistral_api_key'] ?? '')),
			'openai_api_key'     => sanitize_text_field((string) ($input['openai_api_key'] ?? '')),
			'anthropic_api_key'  => sanitize_text_field((string) ($input['anthropic_api_key'] ?? '')),
			'gemini_api_key'     => sanitize_text_field((string) ($input['gemini_api_key'] ?? '')),
			'ai_model'           => (static function () use ($input): string {
				$model = sanitize_text_field((string) ($input['ai_model'] ?? ''));

				return in_array($model, Vision_Ai::allowed_model_ids(), true) ? $model : '';
			})(),
			'ai_budget_month'    => max(0, (int) ($input['ai_budget_month'] ?? 0)),
			'site_url'           => esc_url_raw((string) ($input['site_url'] ?? '')),
			// Géré depuis la page Icônes — ne pas écraser ici.
			'site_favicons'      => ! empty($current['site_favicons']),
		];

		Plugin::instance()->clear_settings_cache();

		return $out;
	}

	public function ajax_reset_usage(): void
	{
		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Permission refusée.', 'lumen-wp')], 403);
		}
		check_ajax_referer('lumen_wp_admin', 'nonce');
		Vision_Ai::reset_usage();
		wp_send_json_success(['usage' => Vision_Ai::usage()]);
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
							<p class="description"><?php esc_html_e('Sinon : l’original est conservé et les variantes sont écrites à côté.', 'lumen-wp'); ?></p>
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
						<th scope="row"><?php esc_html_e('Fournisseur IA Vision', 'lumen-wp'); ?></th>
						<td>
							<?php $provider = (string) ($settings['ai_provider'] ?? 'none'); ?>
							<select name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[ai_provider]" id="lumen-wp-ai-provider">
								<option value="none" <?php selected($provider, 'none'); ?>><?php esc_html_e('Aucun (SEO local uniquement)', 'lumen-wp'); ?></option>
								<option value="mistral" <?php selected($provider, 'mistral'); ?>>Mistral</option>
								<option value="openai" <?php selected($provider, 'openai'); ?>>OpenAI</option>
								<option value="anthropic" <?php selected($provider, 'anthropic'); ?>>Anthropic</option>
								<option value="gemini" <?php selected($provider, 'gemini'); ?>>Google Gemini</option>
							</select>
							<p class="description"><?php esc_html_e('Utilisé pour « Suggérer », le traitement en masse et le SEO auto à l’upload.', 'lumen-wp'); ?></p>
						</td>
					</tr>
					<tr id="lumen-wp-api-key-row" <?php echo $provider === 'none' ? 'hidden' : ''; ?>>
						<th scope="row"><?php esc_html_e('Clé API', 'lumen-wp'); ?></th>
						<td>
							<?php
							$api_keys = [
								'mistral'   => [
									'label' => 'Mistral',
									'value' => (string) $settings['mistral_api_key'],
									'name'  => 'mistral_api_key',
								],
								'openai'    => [
									'label' => 'OpenAI',
									'value' => (string) ($settings['openai_api_key'] ?? ''),
									'name'  => 'openai_api_key',
								],
								'anthropic' => [
									'label' => 'Anthropic',
									'value' => (string) ($settings['anthropic_api_key'] ?? ''),
									'name'  => 'anthropic_api_key',
								],
								'gemini'    => [
									'label' => 'Gemini',
									'value' => (string) ($settings['gemini_api_key'] ?? ''),
									'name'  => 'gemini_api_key',
								],
							];
							foreach ($api_keys as $key_provider => $key_meta) :
								$field_id = 'lumen-wp-key-' . $key_provider;
								$visible  = $provider === $key_provider;
								?>
								<div
									class="lumen-wp-api-key"
									data-provider="<?php echo esc_attr($key_provider); ?>"
									<?php echo $visible ? '' : 'hidden'; ?>
								>
									<label class="screen-reader-text" for="<?php echo esc_attr($field_id); ?>">
										<?php
										printf(
											/* translators: %s: provider name */
											esc_html__('Clé API %s', 'lumen-wp'),
											esc_html($key_meta['label'])
										);
										?>
									</label>
									<div class="lumen-wp-api-key__field">
										<input
											type="password"
											class="regular-text lumen-wp-api-key__input"
											id="<?php echo esc_attr($field_id); ?>"
											name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[<?php echo esc_attr($key_meta['name']); ?>]"
											value="<?php echo esc_attr($key_meta['value']); ?>"
											autocomplete="off"
											spellcheck="false"
										/>
										<button
											type="button"
											class="button lumen-wp-api-key__toggle"
											aria-controls="<?php echo esc_attr($field_id); ?>"
											aria-pressed="false"
											data-label-show="<?php echo esc_attr__('Afficher', 'lumen-wp'); ?>"
											data-label-hide="<?php echo esc_attr__('Masquer', 'lumen-wp'); ?>"
										>
											<?php esc_html_e('Afficher', 'lumen-wp'); ?>
										</button>
									</div>
								</div>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Modèle IA', 'lumen-wp'); ?></th>
						<td>
							<?php
							$current_model = (string) ($settings['ai_model'] ?? '');
							$catalog      = Vision_Ai::models_catalog();
							$models_for   = ($provider !== 'none' && isset($catalog[$provider]))
								? $catalog[$provider]
								: ['' => __('Choisir d’abord un fournisseur', 'lumen-wp')];
							?>
							<select
								name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[ai_model]"
								id="lumen-wp-ai-model"
								class="lumen-wp-select"
								data-catalog="<?php echo esc_attr(wp_json_encode($catalog) ?: '{}'); ?>"
								<?php disabled($provider === 'none'); ?>
							>
								<?php foreach ($models_for as $value => $label) : ?>
									<option value="<?php echo esc_attr((string) $value); ?>" <?php selected($current_model, (string) $value); ?>>
										<?php echo esc_html($label); ?>
									</option>
								<?php endforeach; ?>
								<?php if ($current_model !== '' && ! isset($models_for[$current_model])) : ?>
									<option value="<?php echo esc_attr($current_model); ?>" selected>
										<?php echo esc_html($current_model); ?>
									</option>
								<?php endif; ?>
							</select>
							<p class="description"><?php esc_html_e('Liste adaptée au fournisseur sélectionné. « Défaut » utilise le modèle recommandé par Lumen.', 'lumen-wp'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Budget IA / mois', 'lumen-wp'); ?></th>
						<td>
							<input type="number" min="0" step="1" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[ai_budget_month]" value="<?php echo esc_attr((string) (int) ($settings['ai_budget_month'] ?? 0)); ?>" />
							<p class="description"><?php esc_html_e('0 = illimité (côté Lumen). Au-delà, fallback SEO local. Le solde réel se consulte chez le fournisseur.', 'lumen-wp'); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(__('Enregistrer', 'lumen-wp')); ?>
			</form>

			<p class="description">
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: dashboard link */
						__('Le compteur d’usage IA est affiché sur le %s.', 'lumen-wp'),
						'<a href="' . esc_url(admin_url('admin.php?page=lumen-wp')) . '">' . esc_html__('Dashboard', 'lumen-wp') . '</a>'
					),
					[
						'a' => [
							'href' => true,
						],
					]
				);
				?>
			</p>
		</div>
		<?php
	}
}
