<?php

declare(strict_types=1);

namespace LumenWp;

final class Faq_Schema
{
	/**
	 * @param list<array{question?: string, answer?: string}> $items
	 * @return array<string, mixed>
	 */
	public static function build(array $items, string $url): array
	{
		$entities = [];
		foreach ($items as $item) {
			$q = trim((string) ($item['question'] ?? ''));
			$a = trim((string) ($item['answer'] ?? ''));
			if ($q === '' || $a === '') {
				continue;
			}
			$entities[] = [
				'@type' => 'Question',
				'name'  => $q,
				'acceptedAnswer' => [
					'@type' => 'Answer',
					'text'  => function_exists('wp_strip_all_tags') ? wp_strip_all_tags($a) : strip_tags($a),
				],
			];
		}
		$out = [
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		];
		if ($url !== '') {
			$out['url'] = $url;
		}

		return $out;
	}
}
