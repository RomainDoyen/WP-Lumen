<?php

declare(strict_types=1);

namespace LumenWp;

/**
 * Assisted fixes for SEO/GEO audit issues.
 */
final class Audit_Fixer
{
	public const AS_HOOK = 'lumen_wp_audit_process_one';

	public function register(): void
	{
		add_action(self::AS_HOOK, [$this, 'as_process_one'], 10, 1);
	}

	/**
	 * @param array{id?: int, force?: bool, use_ai?: bool}|int $args
	 */
	public function as_process_one($args): void
	{
		if (is_numeric($args)) {
			$id     = (int) $args;
			$force  = true;
			$use_ai = true;
		} else {
			$id     = (int) ($args['id'] ?? 0);
			$force  = ! empty($args['force']);
			$use_ai = ! empty($args['use_ai']);
		}
		if ($id <= 0) {
			return;
		}
		(new Hooks())->process($id, $force, $use_ai);
	}

	/**
	 * @param list<int> $entity_ids
	 * @return array{success: bool, message: string, fixed: int, queued: int, skipped: int, details: list<string>}
	 */
	public function fix(string $issue_id, array $entity_ids = []): array
	{
		$entity_ids = array_values(array_filter(array_map('intval', $entity_ids)));

		switch ($issue_id) {
			case 'missing_alt':
				return $this->queue_attachments($entity_ids, true, true, __('Génération alt / SEO planifiée', 'lumen-wp'));
			case 'unoptimized_images':
				return $this->queue_attachments($entity_ids, true, false, __('Compression planifiée', 'lumen-wp'));
			case 'unoptimized_videos':
				return $this->queue_attachments($entity_ids, true, Vision_Ai::is_configured(), __('Traitement vidéo planifié', 'lumen-wp'));
			case 'missing_excerpt':
				return $this->fix_missing_excerpt($entity_ids);
			case 'missing_seo_meta':
				return $this->fix_missing_seo_meta($entity_ids);
			case 'missing_llms_txt':
				return $this->fix_missing_llms_txt();
			default:
				return [
					'success' => false,
					'message' => __('Cette correction doit être faite manuellement.', 'lumen-wp'),
					'fixed'   => 0,
					'queued'  => 0,
					'skipped' => count($entity_ids),
					'details' => [],
				];
		}
	}

	/**
	 * @return array{success: bool, message: string, fixed: int, queued: int, skipped: int, details: list<string>}
	 */
	public function fix_all(): array
	{
		$cached = get_option(Seo_Geo_Auditor::OPTION_LAST);
		if (! is_array($cached) || empty($cached['items'])) {
			return [
				'success' => false,
				'message' => __('Lancez d’abord un audit SEO/GEO.', 'lumen-wp'),
				'fixed'   => 0,
				'queued'  => 0,
				'skipped' => 0,
				'details' => [],
			];
		}

		$total_queued  = 0;
		$total_fixed   = 0;
		$total_skipped = 0;
		$details       = [];

		foreach ($cached['items'] as $item) {
			if (empty($item['fixable']) || empty($item['id'])) {
				continue;
			}
			$result = $this->fix(
				(string) $item['id'],
				array_map('intval', (array) ($item['affected_ids'] ?? []))
			);
			$total_queued  += (int) ($result['queued'] ?? 0);
			$total_fixed   += (int) ($result['fixed'] ?? 0);
			$total_skipped += (int) ($result['skipped'] ?? 0);
			if (! empty($result['message'])) {
				$details[] = (string) ($item['title'] ?? '') . ' — ' . $result['message'];
			}
		}

		$has_work = $total_queued > 0 || $total_fixed > 0;

		return [
			'success' => $has_work,
			'message' => $has_work
				? sprintf(
					/* translators: 1: queued 2: fixed */
					__('%1$d planifiée(s), %2$d appliquée(s). Suivi via Traitement / Validation.', 'lumen-wp'),
					$total_queued,
					$total_fixed
				)
				: __('Aucune correction à planifier.', 'lumen-wp'),
			'fixed'   => $total_fixed,
			'queued'  => $total_queued,
			'skipped' => $total_skipped,
			'details' => $details,
		];
	}

	/**
	 * @param list<int> $ids
	 * @return array{success: bool, message: string, fixed: int, queued: int, skipped: int, details: list<string>}
	 */
	private function queue_attachments(array $ids, bool $force, bool $use_ai, string $label): array
	{
		$queued  = 0;
		$fixed   = 0;
		$skipped = 0;
		$details = [];
		$hooks   = new Hooks();
		$use_as  = As_Bridge::available();

		foreach ($ids as $id) {
			if ($id <= 0 || get_post_type($id) !== 'attachment') {
				++$skipped;
				continue;
			}

			if ($use_as) {
				as_enqueue_async_action(
					self::AS_HOOK,
					[
						[
							'id'     => $id,
							'force'  => $force,
							'use_ai' => $use_ai,
						],
					],
					'lumen-wp-audit',
					true
				);
				++$queued;
				continue;
			}

			$result = $hooks->process($id, $force, $use_ai);
			if (! empty($result['ok'])) {
				++$fixed;
			} else {
				++$skipped;
				$details[] = '#' . $id . ' — ' . (string) ($result['message'] ?? '');
			}
		}

		if ($use_as && $queued > 0) {
			As_Bridge::enqueue_bulk_tick();
		}

		$ok = $queued > 0 || $fixed > 0;

		return [
			'success' => $ok,
			'message' => $queued > 0
				? sprintf(
					/* translators: 1: label 2: count */
					__('%1$s : %2$d média(s) en file Action Scheduler.', 'lumen-wp'),
					$label,
					$queued
				)
				: sprintf(
					/* translators: 1: label 2: count */
					__('%1$s : %2$d média(s) traité(s).', 'lumen-wp'),
					$label,
					$fixed
				),
			'fixed'   => $fixed,
			'queued'  => $queued,
			'skipped' => $skipped,
			'details' => $details,
		];
	}

	/**
	 * @param list<int> $entity_ids
	 * @return array{success: bool, message: string, fixed: int, queued: int, skipped: int, details: list<string>}
	 */
	private function fix_missing_excerpt(array $entity_ids): array
	{
		$fixed   = 0;
		$details = [];

		foreach ($entity_ids as $post_id) {
			$post = get_post($post_id);
			if (! $post || ! in_array($post->post_type, ['post', 'page'], true)) {
				continue;
			}
			if (trim((string) $post->post_excerpt) !== '') {
				continue;
			}
			$content = wp_strip_all_tags((string) $post->post_content);
			if (trim($content) === '') {
				continue;
			}
			$excerpt = $this->build_meta_description($content);
			wp_update_post(
				[
					'ID'           => $post_id,
					'post_excerpt' => sanitize_textarea_field($excerpt),
				]
			);
			++$fixed;
			$details[] = sprintf(
				/* translators: %s: title */
				__('Extrait généré pour « %s »', 'lumen-wp'),
				get_the_title($post_id)
			);
		}

		return [
			'success' => $fixed > 0,
			'message' => sprintf(
				/* translators: %d: count */
				__('%d extrait(s) généré(s).', 'lumen-wp'),
				$fixed
			),
			'fixed'   => $fixed,
			'queued'  => 0,
			'skipped' => max(0, count($entity_ids) - $fixed),
			'details' => $details,
		];
	}

	/**
	 * @param list<int> $entity_ids
	 * @return array{success: bool, message: string, fixed: int, queued: int, skipped: int, details: list<string>}
	 */
	private function fix_missing_seo_meta(array $entity_ids): array
	{
		$bridge = new Seo_Plugin_Bridge();
		if ($bridge->get_active_plugin() === null) {
			return [
				'success' => false,
				'message' => __('Aucun plugin SEO détecté (Yoast, Rank Math, SEOPress).', 'lumen-wp'),
				'fixed'   => 0,
				'queued'  => 0,
				'skipped' => count($entity_ids),
				'details' => [],
			];
		}

		$fixed   = 0;
		$details = [];

		foreach ($entity_ids as $post_id) {
			$post = get_post($post_id);
			if (! $post || ! in_array($post->post_type, ['post', 'page'], true)) {
				continue;
			}
			$current = $bridge->read_post_seo($post_id);
			$content = wp_strip_all_tags((string) $post->post_content);
			$title   = trim($current['title']) !== ''
				? $current['title']
				: $this->build_meta_title((string) $post->post_title);
			$desc = trim($current['description']) !== ''
				? $current['description']
				: $this->build_meta_description(
					trim((string) $post->post_excerpt) !== '' ? (string) $post->post_excerpt : $content
				);

			if ($bridge->sync_post($post_id, ['title' => $title, 'description' => $desc])) {
				++$fixed;
				$details[] = sprintf(
					/* translators: %s: title */
					__('Meta SEO synchronisée pour « %s »', 'lumen-wp'),
					$post->post_title
				);
			}
		}

		return [
			'success' => $fixed > 0,
			'message' => sprintf(
				/* translators: %d: count */
				__('%d meta SEO synchronisée(s).', 'lumen-wp'),
				$fixed
			),
			'fixed'   => $fixed,
			'queued'  => 0,
			'skipped' => max(0, count($entity_ids) - $fixed),
			'details' => $details,
		];
	}

	/**
	 * @return array{success: bool, message: string, fixed: int, queued: int, skipped: int, details: list<string>}
	 */
	private function fix_missing_llms_txt(): array
	{
		$result = (new Llms_Txt())->generate();

		return [
			'success' => ! empty($result['success']),
			'message' => (string) ($result['message'] ?? ''),
			'fixed'   => ! empty($result['success']) ? 1 : 0,
			'queued'  => 0,
			'skipped' => empty($result['success']) ? 1 : 0,
			'details' => ! empty($result['url']) ? [(string) $result['url']] : [],
		];
	}

	private function build_meta_title(string $title): string
	{
		$title = trim(preg_replace('/\s+/u', ' ', $title) ?: '');
		if (function_exists('mb_substr')) {
			return mb_substr($title, 0, 60);
		}

		return substr($title, 0, 60);
	}

	private function build_meta_description(string $text): string
	{
		$text = trim(preg_replace('/\s+/u', ' ', $text) ?: '');
		if (function_exists('mb_substr')) {
			$cut = mb_substr($text, 0, 160);
			if (mb_strlen($text) > 160) {
				$cut = rtrim($cut, " \t.,;:—-") . '…';
			}

			return $cut;
		}
		$cut = substr($text, 0, 160);
		if (strlen($text) > 160) {
			$cut = rtrim($cut, " \t.,;:—-") . '…';
		}

		return $cut;
	}
}
