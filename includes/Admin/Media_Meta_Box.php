<?php

declare(strict_types=1);

namespace LumenWp\Admin;

use LumenWp\Hooks;
use LumenWp\Pack;
use LumenWp\Plugin;
use LumenWp\Seo;

final class Media_Meta_Box
{
	public function register(): void
	{
		add_action('add_meta_boxes', [$this, 'add_meta_box']);
		add_action('save_post_attachment', [$this, 'save'], 20, 1);
		add_action('wp_ajax_lumen_wp_suggest', [$this, 'ajax_suggest']);
		add_action('wp_ajax_lumen_wp_reprocess', [$this, 'ajax_reprocess']);
		add_filter('attachment_fields_to_edit', [$this, 'attachment_fields'], 10, 2);
	}

	public function add_meta_box(): void
	{
		add_meta_box(
			'lumen-wp-meta',
			__('Lumen', 'lumen-wp'),
			[$this, 'render'],
			'attachment',
			'normal',
			'high'
		);
	}

	/**
	 * @param \WP_Post $post
	 */
	public function render($post): void
	{
		if (! $post instanceof \WP_Post || ! wp_attachment_is_image($post)) {
			echo '<p>' . esc_html__('Réservé aux images.', 'lumen-wp') . '</p>';

			return;
		}

		$attachment_id = (int) $post->ID;
		$seo           = get_post_meta($attachment_id, Plugin::META_SEO, true);
		if (! is_array($seo)) {
			$seo = (new Seo())->build_from_filename($attachment_id);
		}

		$status    = (string) get_post_meta($attachment_id, Plugin::META_STATUS, true);
		$error     = (string) get_post_meta($attachment_id, Plugin::META_ERROR, true);
		$gutenberg = (string) get_post_meta($attachment_id, Plugin::META_GUTENBERG, true);
		$jsonld    = get_post_meta($attachment_id, Plugin::META_JSONLD, true);
		$json_text = is_array($jsonld) ? wp_json_encode($jsonld, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';

		wp_nonce_field('lumen_wp_save_meta', 'lumen_wp_meta_nonce');
		?>
		<div class="lumen-wp-metabox" data-attachment-id="<?php echo esc_attr((string) $attachment_id); ?>">
			<?php
			Brand::render_header(
				__('Pack SEO', 'lumen-wp'),
				__('Alts, JSON-LD et bloc Gutenberg prêts à coller.', 'lumen-wp')
			);
			?>
			<p>
				<strong><?php esc_html_e('Statut :', 'lumen-wp'); ?></strong>
				<span class="lumen-wp-status lumen-wp-status--<?php echo esc_attr($status !== '' ? $status : 'none'); ?>">
					<?php echo esc_html($status !== '' ? $status : '—'); ?>
				</span>
				<?php if ($error !== '') : ?>
					<br /><span class="lumen-wp-error"><?php echo esc_html($error); ?></span>
				<?php endif; ?>
			</p>

			<div id="lumen-wp-mistral-banner" class="notice notice-warning inline" hidden></div>

			<p>
				<label>
					<?php esc_html_e('Titre', 'lumen-wp'); ?><br />
					<input type="text" class="widefat" name="lumen_seo[title]" value="<?php echo esc_attr((string) ($seo['title'] ?? '')); ?>" />
				</label>
			</p>
			<p>
				<label>
					<?php esc_html_e('Alt SEO (≤125)', 'lumen-wp'); ?><br />
					<input type="text" class="widefat" name="lumen_seo[alt_text_seo]" value="<?php echo esc_attr((string) ($seo['alt_text_seo'] ?? '')); ?>" maxlength="125" />
				</label>
			</p>
			<p>
				<label>
					<?php esc_html_e('Alt WCAG (≤150)', 'lumen-wp'); ?><br />
					<input type="text" class="widefat" name="lumen_seo[alt_text_wcag]" value="<?php echo esc_attr((string) ($seo['alt_text_wcag'] ?? '')); ?>" maxlength="150" />
				</label>
			</p>
			<p>
				<label>
					<?php esc_html_e('Alt court (≤60)', 'lumen-wp'); ?><br />
					<input type="text" class="widefat" name="lumen_seo[alt_text_short]" value="<?php echo esc_attr((string) ($seo['alt_text_short'] ?? '')); ?>" maxlength="60" />
				</label>
			</p>
			<p>
				<label>
					<?php esc_html_e('Légende', 'lumen-wp'); ?><br />
					<textarea class="widefat" rows="2" name="lumen_seo[caption]"><?php echo esc_textarea((string) ($seo['caption'] ?? '')); ?></textarea>
				</label>
			</p>
			<p>
				<label>
					<?php esc_html_e('Description', 'lumen-wp'); ?><br />
					<textarea class="widefat" rows="3" name="lumen_seo[description]"><?php echo esc_textarea((string) ($seo['description'] ?? '')); ?></textarea>
				</label>
			</p>

			<p class="lumen-wp-actions">
				<button type="button" class="button" id="lumen-wp-suggest">
					<?php esc_html_e('Suggérer (IA)', 'lumen-wp'); ?>
				</button>
				<button type="button" class="button" id="lumen-wp-reprocess">
					<?php esc_html_e('Re-traiter (optimiser + pack)', 'lumen-wp'); ?>
				</button>
			</p>

			<p>
				<label><?php esc_html_e('Bloc Gutenberg', 'lumen-wp'); ?></label>
				<textarea id="lumen-wp-gutenberg" class="widefat code" rows="10" readonly><?php echo esc_textarea($gutenberg); ?></textarea>
				<button type="button" class="button lumen-wp-copy" data-target="lumen-wp-gutenberg">
					<?php esc_html_e('Copier Gutenberg', 'lumen-wp'); ?>
				</button>
			</p>
			<p>
				<label><?php esc_html_e('JSON-LD ImageObject', 'lumen-wp'); ?></label>
				<textarea id="lumen-wp-jsonld" class="widefat code" rows="10" readonly><?php echo esc_textarea((string) $json_text); ?></textarea>
				<button type="button" class="button lumen-wp-copy" data-target="lumen-wp-jsonld">
					<?php esc_html_e('Copier JSON-LD', 'lumen-wp'); ?>
				</button>
			</p>
		</div>
		<?php
	}

	public function save(int $attachment_id): void
	{
		if (! isset($_POST['lumen_wp_meta_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['lumen_wp_meta_nonce'])), 'lumen_wp_save_meta')) {
			return;
		}

		if (! current_user_can('edit_post', $attachment_id)) {
			return;
		}

		if (! isset($_POST['lumen_seo']) || ! is_array($_POST['lumen_seo'])) {
			return;
		}

		$raw = wp_unslash($_POST['lumen_seo']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$existing = get_post_meta($attachment_id, Plugin::META_SEO, true);
		$slug     = is_array($existing) && ! empty($existing['slug'])
			? (string) $existing['slug']
			: sanitize_title((string) get_the_title($attachment_id));

		$seo = [
			'slug'            => $slug !== '' ? $slug : 'image',
			'title'           => sanitize_text_field((string) ($raw['title'] ?? '')),
			'alt_text_seo'    => sanitize_text_field((string) ($raw['alt_text_seo'] ?? '')),
			'alt_text_wcag'   => sanitize_text_field((string) ($raw['alt_text_wcag'] ?? '')),
			'alt_text_short'  => sanitize_text_field((string) ($raw['alt_text_short'] ?? '')),
			'caption'         => sanitize_textarea_field((string) ($raw['caption'] ?? '')),
			'description'     => sanitize_textarea_field((string) ($raw['description'] ?? '')),
			'metadata_source' => 'manual',
		];
		$seo['alt_text'] = $seo['alt_text_wcag'] !== '' ? $seo['alt_text_wcag'] : $seo['alt_text_seo'];

		(new Seo())->apply_to_attachment($attachment_id, $seo, false);

		$variants = get_post_meta($attachment_id, Plugin::META_VARIANTS, true);
		if (is_array($variants) && $variants !== []) {
			(new Pack())->build_and_store($attachment_id, $variants, $seo);
		}
	}

	public function ajax_suggest(): void
	{
		$this->guard_ajax();

		$id = isset($_POST['id']) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ($id <= 0 || ! wp_attachment_is_image($id)) {
			wp_send_json_error(['message' => __('Attachment invalide.', 'lumen-wp')], 400);
		}

		$seo_service = new Seo();
		$fallback    = get_post_meta($id, Plugin::META_SEO, true);
		if (! is_array($fallback)) {
			$fallback = $seo_service->build_from_filename($id);
		}

		$result = $seo_service->enrich_with_mistral($id, $fallback);
		$seo_service->apply_to_attachment($id, $result['seo'], false);

		$variants = get_post_meta($id, Plugin::META_VARIANTS, true);
		if (is_array($variants) && $variants !== []) {
			(new Pack())->build_and_store($id, $variants, $result['seo']);
		}

		$jsonld = get_post_meta($id, Plugin::META_JSONLD, true);

		wp_send_json_success(
			[
				'seo'          => $result['seo'],
				'rate_limited' => ! empty($result['rate_limited']),
				'error'        => $result['error'] ?? '',
				'gutenberg'    => (string) get_post_meta($id, Plugin::META_GUTENBERG, true),
				'jsonld'       => is_array($jsonld)
					? wp_json_encode($jsonld, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
					: '',
			]
		);
	}

	public function ajax_reprocess(): void
	{
		$this->guard_ajax();

		$id = isset($_POST['id']) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$use_mistral = ! empty($_POST['use_mistral']); // phpcs:ignore WordPress.Security.NonceVerification

		if ($id <= 0) {
			wp_send_json_error(['message' => __('ID invalide.', 'lumen-wp')], 400);
		}

		$result = (new Hooks())->process($id, true, $use_mistral);
		$seo    = get_post_meta($id, Plugin::META_SEO, true);
		$jsonld = get_post_meta($id, Plugin::META_JSONLD, true);

		$payload = array_merge(
			$result,
			[
				'seo'       => is_array($seo) ? $seo : [],
				'gutenberg' => (string) get_post_meta($id, Plugin::META_GUTENBERG, true),
				'jsonld'    => is_array($jsonld)
					? wp_json_encode($jsonld, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
					: '',
				'status'    => (string) get_post_meta($id, Plugin::META_STATUS, true),
				'error'     => (string) get_post_meta($id, Plugin::META_ERROR, true),
			]
		);

		if (! empty($result['ok'])) {
			wp_send_json_success($payload);
		}

		wp_send_json_error($payload);
	}

	/**
	 * Lightweight status in media modal.
	 *
	 * @param array<string, mixed> $form_fields
	 * @param \WP_Post             $post
	 * @return array<string, mixed>
	 */
	public function attachment_fields(array $form_fields, $post): array
	{
		if (! $post instanceof \WP_Post || ! wp_attachment_is_image($post)) {
			return $form_fields;
		}

		$status = (string) get_post_meta($post->ID, Plugin::META_STATUS, true);
		$form_fields['lumen_status'] = [
			'label' => __('Lumen', 'lumen-wp'),
			'input' => 'html',
			'html'  => '<span class="lumen-wp-status">' . esc_html($status !== '' ? $status : '—') . '</span>'
				. ' <a href="' . esc_url(get_edit_post_link($post->ID)) . '">' . esc_html__('Ouvrir le pack SEO', 'lumen-wp') . '</a>',
		];

		return $form_fields;
	}

	private function guard_ajax(): void
	{
		if (! current_user_can('upload_files')) {
			wp_send_json_error(['message' => __('Permission refusée.', 'lumen-wp')], 403);
		}
		check_ajax_referer('lumen_wp_admin', 'nonce');
	}
}
