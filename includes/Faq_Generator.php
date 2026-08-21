<?php

declare(strict_types=1);

namespace LumenWp;

final class Faq_Generator
{
	private const MIN_ITEMS = 2;
	private const MAX_ITEMS = 10;

	/**
	 * @return list<array{question: string, answer: string}>
	 */
	public static function extract_from_content(string $content): array
	{
		if (trim($content) === '') {
			return [];
		}

		$items = array_merge(
			self::extract_question_headings($content),
			self::extract_faq_section($content),
			self::extract_details($content),
			self::extract_plugin_faq_blocks($content)
		);

		return self::dedupe_items($items);
	}

	public static function has_stored(int $post_id): bool
	{
		$raw = get_post_meta($post_id, Plugin::META_FAQ, true);
		if (is_string($raw) && $raw !== '') {
			$decoded = json_decode($raw, true);
			$raw     = is_array($decoded) ? $decoded : [];
		}

		return is_array($raw) && ! empty($raw['mainEntity']);
	}

	/**
	 * @return list<array{question: string, answer: string}>
	 */
	public static function generate_and_store(int $post_id): array
	{
		$post = get_post($post_id);
		if (! $post instanceof \WP_Post) {
			return [];
		}
		$items    = self::extract_from_content((string) $post->post_content);
		$settings = Plugin::instance()->settings();
		if (! empty($settings['ai_enrich_faq']) && Vision_Ai::is_configured() && $items !== []) {
			$items = self::enrich_with_ai($items, $post); // fail → keep $items
		}
		if (count($items) < self::MIN_ITEMS) {
			return [];
		}
		$schema = Faq_Schema::build($items, get_permalink($post_id) ?: '');
		update_post_meta($post_id, Plugin::META_FAQ, $schema);

		return $items;
	}

	/**
	 * @param list<array{question: string, answer: string}> $items
	 * @return list<array{question: string, answer: string}>
	 */
	private static function enrich_with_ai(array $items, \WP_Post $post): array
	{
		if (Vision_Ai::budget_reached()) {
			return $items;
		}

		$payload = wp_json_encode($items);
		if (! is_string($payload) || $payload === '') {
			return $items;
		}

		$system = 'Tu es expert SEO et rédaction WordPress en français.
On te donne des paires question/réponse extraites d’un contenu.
Reformule-les de façon claire et concise SANS inventer de sujets hors contenu.
Réponds UNIQUEMENT avec un objet JSON valide (sans markdown) de la forme :
{"items":[{"question":"...","answer":"..."}]}
Conserve le même nombre de paires (max 10).';

		$user = "Titre : " . (string) $post->post_title . "\nPaires :\n" . $payload;

		$decoded = Vision_Ai::complete_json_prompt($system, $user);
		$refined = self::items_from_ai_payload($decoded);
		if ($refined === []) {
			return $items;
		}

		return self::dedupe_items($refined);
	}

	/**
	 * @param array<mixed> $decoded
	 * @return list<array{question: string, answer: string}>
	 */
	private static function items_from_ai_payload(array $decoded): array
	{
		$rows = $decoded;
		if (isset($decoded['items']) && is_array($decoded['items'])) {
			$rows = $decoded['items'];
		} elseif (isset($decoded['faq']) && is_array($decoded['faq'])) {
			$rows = $decoded['faq'];
		}

		$out = [];
		foreach ($rows as $row) {
			if (! is_array($row)) {
				continue;
			}
			$q = self::normalize_text((string) ($row['question'] ?? $row['q'] ?? ''));
			$a = self::normalize_text((string) ($row['answer'] ?? $row['a'] ?? ''));
			if ($q === '' || $a === '') {
				continue;
			}
			$out[] = [
				'question' => $q,
				'answer'   => $a,
			];
		}

		return $out;
	}

	/**
	 * @return list<array{question: string, answer: string}>
	 */
	private static function extract_question_headings(string $content): array
	{
		$html  = self::prepare_html($content);
		$items = [];

		if (! preg_match_all(
			'/<h([2-4])[^>]*>(.*?)<\/h\1>\s*(<p\b[^>]*>.*?<\/p>)/is',
			$html,
			$matches,
			PREG_SET_ORDER
		)) {
			return [];
		}

		foreach ($matches as $match) {
			$question = self::strip_tags_text((string) ($match[2] ?? ''));
			$answer   = self::strip_tags_text((string) ($match[3] ?? ''));
			if (! self::ends_with_question_mark($question) || trim($answer) === '') {
				continue;
			}
			$items[] = [
				'question' => self::normalize_text($question),
				'answer'   => self::normalize_text($answer),
			];
			if (count($items) >= self::MAX_ITEMS) {
				break;
			}
		}

		return $items;
	}

	/**
	 * @return list<array{question: string, answer: string}>
	 */
	private static function extract_faq_section(string $content): array
	{
		$html = self::prepare_html($content);
		if (! preg_match('/<h[2-4][^>]*>\s*faq\s*<\/h[2-4]>/i', $html, $faq_match, PREG_OFFSET_CAPTURE)) {
			return [];
		}

		$offset  = (int) ($faq_match[0][1] ?? 0);
		$section = substr($html, $offset);
		if (! preg_match_all(
			'/<h([3-4])[^>]*>(.*?)<\/h\1>\s*(<p\b[^>]*>.*?<\/p>)/is',
			$section,
			$matches,
			PREG_SET_ORDER
		)) {
			return [];
		}

		$items = [];
		foreach ($matches as $match) {
			$question = self::strip_tags_text((string) ($match[2] ?? ''));
			$answer   = self::strip_tags_text((string) ($match[3] ?? ''));
			if (trim($question) === '' || trim($answer) === '') {
				continue;
			}
			$items[] = [
				'question' => self::normalize_text($question),
				'answer'   => self::normalize_text($answer),
			];
			if (count($items) >= self::MAX_ITEMS) {
				break;
			}
		}

		return $items;
	}

	/**
	 * @return list<array{question: string, answer: string}>
	 */
	private static function extract_details(string $content): array
	{
		$html  = self::prepare_html($content);
		$items = [];
		if (! preg_match_all(
			'/<details\b[^>]*>\s*<summary\b[^>]*>(.*?)<\/summary>(.*?)<\/details>/is',
			$html,
			$matches,
			PREG_SET_ORDER
		)) {
			return [];
		}

		foreach ($matches as $match) {
			$question = self::strip_tags_text((string) ($match[1] ?? ''));
			$answer   = self::strip_tags_text((string) ($match[2] ?? ''));
			if (! self::ends_with_question_mark($question) || trim($answer) === '') {
				continue;
			}
			$items[] = [
				'question' => self::normalize_text($question),
				'answer'   => self::normalize_text($answer),
			];
			if (count($items) >= self::MAX_ITEMS) {
				break;
			}
		}

		return $items;
	}

	/**
	 * Yoast / Rank Math FAQ blocks (comment JSON + markup classes).
	 *
	 * @return list<array{question: string, answer: string}>
	 */
	private static function extract_plugin_faq_blocks(string $content): array
	{
		$items = [];

		if (preg_match_all(
			'/<!--\s+wp:(?:yoast\/faq-block|rank-math\/faq-block)\s+(\{.*?\})\s+-->/s',
			$content,
			$blocks
		)) {
			foreach ($blocks[1] as $json) {
				$data = json_decode((string) $json, true);
				if (! is_array($data)) {
					continue;
				}
				$questions = [];
				if (isset($data['questions']) && is_array($data['questions'])) {
					$questions = $data['questions'];
				}
				foreach ($questions as $row) {
					if (! is_array($row)) {
						continue;
					}
					$q = (string) ($row['jsonQuestion'] ?? $row['title'] ?? '');
					$a = (string) ($row['jsonAnswer'] ?? $row['content'] ?? '');
					if ($q === '' && isset($row['question'])) {
						$q = is_array($row['question']) ? implode(' ', $row['question']) : (string) $row['question'];
					}
					if ($a === '' && isset($row['answer'])) {
						$a = is_array($row['answer']) ? implode(' ', $row['answer']) : (string) $row['answer'];
					}
					$q = self::normalize_text(self::strip_tags_text($q));
					$a = self::normalize_text(self::strip_tags_text($a));
					if ($q === '' || $a === '') {
						continue;
					}
					$items[] = [
						'question' => $q,
						'answer'   => $a,
					];
				}
			}
		}

		$html = self::prepare_html($content);
		$pairs = [
			['schema-faq-question', 'schema-faq-answer'],
			['rank-math-question', 'rank-math-answer'],
		];
		foreach ($pairs as $pair) {
			$q_class = preg_quote($pair[0], '/');
			$a_class = preg_quote($pair[1], '/');
			if (! preg_match_all(
				'/<([a-z0-9]+)[^>]*class="[^"]*' . $q_class . '[^"]*"[^>]*>(.*?)<\/\1>.*?<([a-z0-9]+)[^>]*class="[^"]*' . $a_class . '[^"]*"[^>]*>(.*?)<\/\3>/is',
				$html,
				$matches,
				PREG_SET_ORDER
			)) {
				continue;
			}
			foreach ($matches as $match) {
				$q = self::normalize_text(self::strip_tags_text((string) ($match[2] ?? '')));
				$a = self::normalize_text(self::strip_tags_text((string) ($match[4] ?? '')));
				if ($q === '' || $a === '') {
					continue;
				}
				$items[] = [
					'question' => $q,
					'answer'   => $a,
				];
			}
		}

		return $items;
	}

	/**
	 * @param list<array{question: string, answer: string}> $items
	 * @return list<array{question: string, answer: string}>
	 */
	private static function dedupe_items(array $items): array
	{
		$seen   = [];
		$result = [];
		foreach ($items as $item) {
			$key = function_exists('mb_strtolower')
				? mb_strtolower($item['question'])
				: strtolower($item['question']);
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$result[]   = $item;
			if (count($result) >= self::MAX_ITEMS) {
				break;
			}
		}

		return $result;
	}

	private static function prepare_html(string $content): string
	{
		if (function_exists('do_blocks')) {
			$content = do_blocks($content);
		}
		if (function_exists('wpautop')) {
			$content = wpautop($content);
		}

		return $content;
	}

	private static function strip_tags_text(string $text): string
	{
		return function_exists('wp_strip_all_tags') ? wp_strip_all_tags($text) : strip_tags($text);
	}

	private static function ends_with_question_mark(string $text): bool
	{
		$text = trim($text);
		if ($text === '') {
			return false;
		}

		if (function_exists('mb_substr')) {
			return mb_substr($text, -1) === '?';
		}

		return substr($text, -1) === '?';
	}

	private static function normalize_text(string $text): string
	{
		$stripped = self::strip_tags_text($text);
		$cleaned  = preg_replace('/\s+/u', ' ', trim($stripped));
		$text     = is_string($cleaned) ? $cleaned : trim($stripped);

		if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($text) > 500) {
			return mb_substr($text, 0, 497) . '…';
		}
		if (strlen($text) > 500) {
			return substr($text, 0, 497) . '…';
		}

		return $text;
	}
}
