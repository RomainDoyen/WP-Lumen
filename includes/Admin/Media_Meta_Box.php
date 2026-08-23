<?php

declare(strict_types=1);

namespace LumenWp\Admin;

use LumenWp\Hooks;
use LumenWp\Job_Repository;
use LumenWp\Media_Types;
use LumenWp\Original_Backup;
use LumenWp\Pack;
use LumenWp\Plugin;
use LumenWp\Seo;
use LumenWp\Video_Schema;
use LumenWp\Vision_Ai;

final class Media_Meta_Box
{
	public function register(): void
	{
		add_action('add_meta_boxes', [$this, 'add_meta_box']);
		add_action('save_post_attachment', [$this, 'save'], 20, 1);
		add_action('wp_ajax_lumen_wp_suggest', [$this, 'ajax_suggest']);
		add_action('wp_ajax_lumen_wp_reprocess', [$this, 'ajax_reprocess']);
		add_action('wp_ajax_lumen_wp_restore_original', [$this, 'ajax_restore']);
		add_filter('attachment_fields_to_edit', [$this, 'attachment_fields'], 10, 2);
		add_filter('attachment_fields_to_save', [$this, 'save_attachment_fields'], 10, 2);
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
		if (! $post instanceof \WP_Post || ! Media_Types::is_supported((int) $post->ID)) {
			echo '<p>' . esc_html__('Réservé aux médias supportés (Images, SVG, PDF, vidéos).', 'lumen-wp') . '</p>';

			return;
		}

		$attachment_id = (int) $post->ID;
		$kind          = Media_Types::kind($attachment_id);
		$is_image      = $kind === Media_Types::KIND_IMAGE;
		$can_ai        = Media_Types::supports_ai($kind);
		$seo           = get_post_meta($attachment_id, Plugin::META_SEO, true);
		if (! is_array($seo)) {
			$seo = (new Seo())->build_from_filename($attachment_id);
		}

		$status    = (string) get_post_meta($attachment_id, Plugin::META_STATUS, true);
		$error     = (string) get_post_meta($attachment_id, Plugin::META_ERROR, true);
		$gutenberg = (string) get_post_meta($attachment_id, Plugin::META_GUTENBERG, true);
		$jsonld    = get_post_meta($attachment_id, Plugin::META_JSONLD, true);
		$json_text = is_array($jsonld) ? wp_json_encode($jsonld, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
		$has_bak   = $is_image && Original_Backup::has($attachment_id);

		wp_nonce_field('lumen_wp_save_meta', 'lumen_wp_meta_nonce');
		?>
		<div class="lumen-wp-metabox lumen-wp-theme-<?php echo esc_attr(Plugin::ui_theme()); ?>" data-attachment-id="<?php echo esc_attr((string) $attachment_id); ?>" data-has-backup="<?php echo $has_bak ? '1' : '0'; ?>" data-kind="<?php echo esc_attr($kind); ?>">
			<?php
			Brand::render_header(
				__('SEO média', 'lumen-wp'),
				$is_image
					? __('Alts, JSON-LD et bloc Gutenberg prêts à coller.', 'lumen-wp')
					: __('Titre, alts, légende et description pour ce média.', 'lumen-wp')
			);
			?>
			<p>
				<strong><?php esc_html_e('Type :', 'lumen-wp'); ?></strong>
				<?php echo esc_html(Media_Types::label($kind)); ?>
				·
				<strong><?php esc_html_e('Statut :', 'lumen-wp'); ?></strong>
				<span class="lumen-wp-status lumen-wp-status--<?php echo esc_attr($status !== '' ? $status : 'none'); ?>">
					<?php echo esc_html($status !== '' ? $status : '—'); ?>
				</span>
				<?php if ($error !== '') : ?>
					<br /><span class="lumen-wp-error"><?php echo esc_html($error); ?></span>
				<?php endif; ?>
			</p>

			<div id="lumen-wp-mistral-banner" class="lumen-wp-mistral-banner notice notice-warning inline" hidden></div>

			<p>
				<label>
					<?php esc_html_e('Titre', 'lumen-wp'); ?><br />
					<input type="text" class="widefat" name="lumen_seo[title]" data-lumen-seo="title" value="<?php echo esc_attr((string) ($seo['title'] ?? '')); ?>" />
				</label>
			</p>
			<p>
				<label>
					<?php esc_html_e('Alt SEO (≤125)', 'lumen-wp'); ?><br />
					<input type="text" class="widefat" name="lumen_seo[alt_text_seo]" data-lumen-seo="alt_text_seo" value="<?php echo esc_attr((string) ($seo['alt_text_seo'] ?? '')); ?>" maxlength="125" />
				</label>
			</p>
			<p>
				<label>
					<?php esc_html_e('Alt WCAG (≤150)', 'lumen-wp'); ?><br />
					<input type="text" class="widefat" name="lumen_seo[alt_text_wcag]" data-lumen-seo="alt_text_wcag" value="<?php echo esc_attr((string) ($seo['alt_text_wcag'] ?? '')); ?>" maxlength="150" />
				</label>
			</p>
			<p>
				<label>
					<?php esc_html_e('Alt court (≤60)', 'lumen-wp'); ?><br />
					<input type="text" class="widefat" name="lumen_seo[alt_text_short]" data-lumen-seo="alt_text_short" value="<?php echo esc_attr((string) ($seo['alt_text_short'] ?? '')); ?>" maxlength="60" />
				</label>
			</p>
			<p>
				<label>
					<?php esc_html_e('Légende', 'lumen-wp'); ?><br />
					<textarea class="widefat" rows="2" name="lumen_seo[caption]" data-lumen-seo="caption"><?php echo esc_textarea((string) ($seo['caption'] ?? '')); ?></textarea>
				</label>
			</p>
			<p>
				<label>
					<?php esc_html_e('Description', 'lumen-wp'); ?><br />
					<textarea class="widefat" rows="3" name="lumen_seo[description]" data-lumen-seo="description"><?php echo esc_textarea((string) ($seo['description'] ?? '')); ?></textarea>
				</label>
			</p>

			<p class="lumen-wp-actions">
				<?php if ($can_ai) : ?>
					<button type="button" class="button lumen-wp-suggest" id="lumen-wp-suggest">
						<?php esc_html_e('Suggérer (IA)', 'lumen-wp'); ?>
					</button>
				<?php endif; ?>
				<button type="button" class="button lumen-wp-reprocess" id="lumen-wp-reprocess">
					<?php
					echo esc_html(
						$is_image
							? __('Re-traiter (optimiser + pack)', 'lumen-wp')
							: __('Re-traiter (SEO)', 'lumen-wp')
					);
					?>
				</button>
				<?php if ($is_image) : ?>
					<button type="button" class="button lumen-wp-restore" id="lumen-wp-restore" <?php echo $has_bak ? '' : 'hidden'; ?>>
						<?php esc_html_e('Restaurer l’original', 'lumen-wp'); ?>
					</button>
				<?php endif; ?>
			</p>
			<?php if ($has_bak) : ?>
				<p class="description" id="lumen-wp-backup-hint">
					<?php esc_html_e('Une sauvegarde de l’original est disponible.', 'lumen-wp'); ?>
				</p>
			<?php endif; ?>

			<?php if ($is_image) : ?>
			<p>
				<label><?php esc_html_e('Bloc Gutenberg', 'lumen-wp'); ?></label>
				<textarea id="lumen-wp-gutenberg" class="widefat code" rows="10" readonly><?php echo esc_textarea($gutenberg); ?></textarea>
				<button type="button" class="button lumen-wp-copy" data-target="lumen-wp-gutenberg">
					<?php esc_html_e('Copier Gutenberg', 'lumen-wp'); ?>
				</button>
			</p>
			<?php endif; ?>
			<?php if ($is_image || $kind === Media_Types::KIND_VIDEO) : ?>
			<p>
				<label><?php echo esc_html($kind === Media_Types::KIND_VIDEO ? __('JSON-LD VideoObject', 'lumen-wp') : __('JSON-LD ImageObject', 'lumen-wp')); ?></label>
				<textarea id="lumen-wp-jsonld" class="widefat code" rows="10" readonly><?php echo esc_textarea((string) $json_text); ?></textarea>
				<button type="button" class="button lumen-wp-copy" data-target="lumen-wp-jsonld">
					<?php esc_html_e('Copier JSON-LD', 'lumen-wp'); ?>
				</button>
			</p>
			<?php endif; ?>
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

		$kind     = Media_Types::kind($attachment_id);
		$variants = get_post_meta($attachment_id, Plugin::META_VARIANTS, true);
		if (is_array($variants) && $variants !== []) {
			(new Pack())->build_and_store($attachment_id, $variants, $seo);
		}
		if ($kind === Media_Types::KIND_VIDEO) {
			Video_Schema::build_and_store($attachment_id, $seo);
		}
	}

	public function ajax_suggest(): void
	{
		$this->guard_ajax();

		$id = isset($_POST['id']) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ($id <= 0 || ! Media_Types::is_supported($id) || ! Media_Types::supports_ai(Media_Types::kind($id))) {
			wp_send_json_error(['message' => __('Attachment invalide pour l\'IA.', 'lumen-wp')], 400);
		}
		if (! current_user_can('edit_post', $id)) {
			wp_send_json_error(['message' => __('Permission refusée.', 'lumen-wp')], 403);
		}

		$seo_service = new Seo();
		$fallback    = get_post_meta($id, Plugin::META_SEO, true);
		if (! is_array($fallback)) {
			$fallback = $seo_service->build_from_filename($id);
		}

		$result = $seo_service->enrich_with_ai($id, $fallback);
		$tokens = $result['tokens'] ?? Vision_Ai::empty_tokens(Vision_Ai::active_provider());
		if (! empty($result['error'])) {
			Job_Repository::record($id, 'suggest', [
				'status'  => 'error',
				'message' => $result['error'] ?? null,
				'tokens'  => $tokens,
			]);
			wp_send_json_error(
				[
					'message'      => (string) $result['error'],
					'rate_limited' => ! empty($result['rate_limited']),
				]
			);
		}

		$seo_service->apply_to_attachment($id, $result['seo'], false);
		delete_post_meta($id, Plugin::META_ERROR);
		update_post_meta($id, Plugin::META_STATUS, 'ok');

		$kind     = Media_Types::kind($id);
		$variants = get_post_meta($id, Plugin::META_VARIANTS, true);
		if (is_array($variants) && $variants !== []) {
			(new Pack())->build_and_store($id, $variants, $result['seo']);
		}
		if ($kind === Media_Types::KIND_VIDEO) {
			Video_Schema::build_and_store($id, $result['seo']);
		}

		$jsonld = get_post_meta($id, Plugin::META_JSONLD, true);

		Job_Repository::record($id, 'suggest', [
			'status'  => 'ok',
			'message' => $result['error'] ?? null,
			'tokens'  => $tokens,
		]);

		wp_send_json_success(
			[
				'seo'       => $result['seo'],
				'warning'   => (string) ($result['warning'] ?? ''),
				'gutenberg' => (string) get_post_meta($id, Plugin::META_GUTENBERG, true),
				'jsonld'    => is_array($jsonld)
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
		if (! current_user_can('edit_post', $id)) {
			wp_send_json_error(['message' => __('Permission refusée.', 'lumen-wp')], 403);
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
				'has_backup'=> Original_Backup::has($id),
			]
		);

		if (! empty($result['ok'])) {
			wp_send_json_success($payload);
		}

		wp_send_json_error($payload);
	}

	public function ajax_restore(): void
	{
		$this->guard_ajax();

		$id = isset($_POST['id']) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ($id <= 0 || Media_Types::kind($id) !== Media_Types::KIND_IMAGE) {
			wp_send_json_error(['message' => __('Attachment invalide.', 'lumen-wp')], 400);
		}
		if (! current_user_can('edit_post', $id)) {
			wp_send_json_error(['message' => __('Permission refusée.', 'lumen-wp')], 403);
		}

		$result = Original_Backup::restore($id);
		if (empty($result['ok'])) {
			wp_send_json_error(['message' => $result['message']]);
		}

		wp_send_json_success(
			[
				'message'    => $result['message'],
				'status'     => (string) get_post_meta($id, Plugin::META_STATUS, true),
				'gutenberg'  => '',
				'jsonld'     => '',
				'has_backup' => Original_Backup::has($id),
			]
		);
	}

	/**
	 * @param array<string, mixed> $form_fields
	 * @param \WP_Post             $post
	 * @return array<string, mixed>
	 */
	public function attachment_fields(array $form_fields, $post): array
	{
		if (! $post instanceof \WP_Post || ! Media_Types::is_supported((int) $post->ID)) {
			return $form_fields;
		}

		$form_fields['lumen_seo_card'] = [
			'label' => __('Lumen', 'lumen-wp'),
			'input' => 'html',
			'html'  => $this->render_modal_card((int) $post->ID),
		];

		return $form_fields;
	}

	/**
	 * @param array<string, mixed> $post
	 * @param array<string, mixed> $attachment
	 * @return array<string, mixed>
	 */
	public function save_attachment_fields(array $post, array $attachment): array
	{
		$id = isset($post['ID']) ? (int) $post['ID'] : 0;
		if ($id <= 0 || ! current_user_can('edit_post', $id) || ! Media_Types::is_supported($id)) {
			return $post;
		}

		$raw = $attachment['lumen_seo'] ?? null;
		if (! is_array($raw)) {
			return $post;
		}

		$existing = get_post_meta($id, Plugin::META_SEO, true);
		if (! is_array($existing)) {
			$existing = (new Seo())->build_from_filename($id);
		}

		if (array_key_exists('title', $raw)) {
			$existing['title'] = sanitize_text_field((string) $raw['title']);
		}
		if (array_key_exists('alt_text_wcag', $raw)) {
			$existing['alt_text_wcag'] = sanitize_text_field((string) $raw['alt_text_wcag']);
		}
		if (array_key_exists('description', $raw)) {
			$existing['description'] = sanitize_textarea_field((string) $raw['description']);
		}

		$existing['alt_text'] = $existing['alt_text_wcag'] !== ''
			? $existing['alt_text_wcag']
			: (string) ($existing['alt_text_seo'] ?? '');
		$existing['metadata_source'] = 'manual';

		// Meta + alt only — do not wp_update_post here: WP calls it on $post after this filter.
		update_post_meta($id, Plugin::META_SEO, $existing);
		$alt = (string) ($existing['alt_text'] ?? ($existing['alt_text_wcag'] ?? $existing['alt_text_seo'] ?? ''));
		update_post_meta($id, '_wp_attachment_image_alt', $alt);

		if (array_key_exists('title', $raw)) {
			$post['post_title'] = $existing['title'];
		}
		if (array_key_exists('description', $raw)) {
			$post['post_content'] = $existing['description'];
		}

		$variants = get_post_meta($id, Plugin::META_VARIANTS, true);
		if (is_array($variants) && $variants !== []) {
			(new Pack())->build_and_store($id, $variants, $existing);
		}
		if (Media_Types::kind($id) === Media_Types::KIND_VIDEO) {
			Video_Schema::build_and_store($id, $existing);
		}

		return $post;
	}

	private function render_modal_card(int $attachment_id): string
	{
		$kind     = Media_Types::kind($attachment_id);
		$is_image = $kind === Media_Types::KIND_IMAGE;
		$can_ai   = Media_Types::supports_ai($kind);
		$seo      = get_post_meta($attachment_id, Plugin::META_SEO, true);
		if (! is_array($seo)) {
			$seo = (new Seo())->build_from_filename($attachment_id);
		}
		$status = (string) get_post_meta($attachment_id, Plugin::META_STATUS, true);
		$error  = (string) get_post_meta($attachment_id, Plugin::META_ERROR, true);
		$edit   = get_edit_post_link($attachment_id, 'raw') ?: '#';

		ob_start();
		?>
		<div
			class="lumen-wp-metabox lumen-wp-metabox--modal lumen-wp-theme-<?php echo esc_attr(Plugin::ui_theme()); ?>"
			data-attachment-id="<?php echo esc_attr((string) $attachment_id); ?>"
			data-kind="<?php echo esc_attr($kind); ?>"
		>
			<?php
			Brand::render_header(
				__('SEO média', 'lumen-wp'),
				__('Titre, alt et description — édition rapide.', 'lumen-wp')
			);
			?>
			<p class="lumen-wp-metabox__meta">
				<strong><?php esc_html_e('Type :', 'lumen-wp'); ?></strong>
				<?php echo esc_html(Media_Types::label($kind)); ?>
				·
				<strong><?php esc_html_e('Statut :', 'lumen-wp'); ?></strong>
				<span class="lumen-wp-status lumen-wp-status--<?php echo esc_attr($status !== '' ? $status : 'none'); ?>">
					<?php echo esc_html($status !== '' ? $status : '—'); ?>
				</span>
				<?php if ($error !== '') : ?>
					<br /><span class="lumen-wp-error"><?php echo esc_html($error); ?></span>
				<?php endif; ?>
			</p>
			<div class="lumen-wp-mistral-banner notice notice-warning inline" hidden></div>
			<p>
				<label><?php esc_html_e('Titre', 'lumen-wp'); ?><br />
					<input type="text" class="widefat" name="attachments[<?php echo esc_attr((string) $attachment_id); ?>][lumen_seo][title]" data-lumen-seo="title" value="<?php echo esc_attr((string) ($seo['title'] ?? '')); ?>" />
				</label>
			</p>
			<p>
				<label><?php esc_html_e('Alt WCAG (≤150)', 'lumen-wp'); ?><br />
					<input type="text" class="widefat" name="attachments[<?php echo esc_attr((string) $attachment_id); ?>][lumen_seo][alt_text_wcag]" data-lumen-seo="alt_text_wcag" value="<?php echo esc_attr((string) ($seo['alt_text_wcag'] ?? '')); ?>" maxlength="150" />
				</label>
			</p>
			<p>
				<label><?php esc_html_e('Description', 'lumen-wp'); ?><br />
					<textarea class="widefat" rows="2" name="attachments[<?php echo esc_attr((string) $attachment_id); ?>][lumen_seo][description]" data-lumen-seo="description"><?php echo esc_textarea((string) ($seo['description'] ?? '')); ?></textarea>
				</label>
			</p>
			<p class="lumen-wp-actions">
				<?php if ($can_ai) : ?>
					<button type="button" class="button lumen-wp-suggest"><?php esc_html_e('Suggérer (IA)', 'lumen-wp'); ?></button>
				<?php endif; ?>
				<button type="button" class="button lumen-wp-reprocess">
					<?php
					echo esc_html(
						$is_image
							? __('Re-traiter (optimiser + pack)', 'lumen-wp')
							: __('Re-traiter (SEO)', 'lumen-wp')
					);
					?>
				</button>
			</p>
			<p class="lumen-wp-metabox__more">
				<a href="<?php echo esc_url($edit); ?>"><?php esc_html_e('Pack SEO complet', 'lumen-wp'); ?></a>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function guard_ajax(): void
	{
		if (! current_user_can('upload_files')) {
			wp_send_json_error(['message' => __('Permission refusée.', 'lumen-wp')], 403);
		}
		check_ajax_referer('lumen_wp_admin', 'nonce');
	}
}
