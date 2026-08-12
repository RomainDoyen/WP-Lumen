<?php

declare(strict_types=1);

namespace LumenWp;

/**
 * Lightweight CSV / XLSX / PDF exporters (no Composer deps).
 */
final class Exporters
{
	/**
	 * @param list<string>             $headers
	 * @param list<list<string|int|float>> $rows
	 */
	public static function send_csv(string $filename, array $headers, array $rows): void
	{
		self::headers('text/csv; charset=utf-8', $filename);
		$out = fopen('php://output', 'w');
		if ($out === false) {
			exit;
		}
		// Excel-friendly UTF-8 BOM.
		fwrite($out, "\xEF\xBB\xBF");
		fputcsv($out, $headers, ';');
		foreach ($rows as $row) {
			fputcsv($out, array_map(static function ($v) {
				return (string) $v;
			}, $row), ';');
		}
		fclose($out);
		exit;
	}

	/**
	 * @param list<array{name: string, headers: list<string>, rows: list<list<string|int|float>>}> $sheets
	 */
	public static function send_xlsx(string $filename, array $sheets): void
	{
		if (! class_exists('ZipArchive')) {
			// Fallback: first sheet as CSV.
			$first = $sheets[0] ?? ['headers' => [], 'rows' => []];
			self::send_csv(preg_replace('/\.xlsx$/i', '.csv', $filename) ?: ($filename . '.csv'), $first['headers'], $first['rows']);
		}

		$zip = new \ZipArchive();
		$tmp = wp_tempnam('lumen-xlsx');
		if ($tmp === false || $zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
			wp_die(esc_html__('Impossible de créer le fichier Excel.', 'lumen-wp'));
		}

		$sheet_files = [];
		$i           = 0;
		foreach ($sheets as $sheet) {
			$i++;
			$name = self::safe_sheet_name((string) ($sheet['name'] ?? ('Sheet' . $i)), $i);
			$path = 'xl/worksheets/sheet' . $i . '.xml';
			$zip->addFromString($path, self::worksheet_xml($sheet['headers'] ?? [], $sheet['rows'] ?? []));
			$sheet_files[] = ['name' => $name, 'path' => $path, 'id' => $i];
		}

		$zip->addFromString('[Content_Types].xml', self::content_types_xml(count($sheet_files)));
		$zip->addFromString('_rels/.rels', self::rels_root_xml());
		$zip->addFromString('xl/workbook.xml', self::workbook_xml($sheet_files));
		$zip->addFromString('xl/_rels/workbook.xml.rels', self::workbook_rels_xml(count($sheet_files)));
		$zip->addFromString('xl/styles.xml', self::styles_xml());
		$zip->close();

		self::headers('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $filename);
		readfile($tmp);
		@unlink($tmp);
		exit;
	}

	/**
	 * Structured branded PDF report.
	 *
	 * @param array{
	 *   title: string,
	 *   subtitle?: string,
	 *   meta?: list<array{label: string, value: string}>,
	 *   kpis?: list<array{label: string, value: string, tone?: string}>,
	 *   sections?: list<array{
	 *     title: string,
	 *     type?: 'kv'|'table'|'list',
	 *     headers?: list<string>,
	 *     rows?: list<list<string|int|float>>,
	 *     pairs?: list<array{0?: string, 1?: string}|list{string, string}>,
	 *     items?: list<string>
	 *   }>
	 * } $document
	 */
	public static function send_pdf(string $filename, array $document): void
	{
		$pdf = self::build_report_pdf($document);
		self::headers('application/pdf', $filename);
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private static function headers(string $mime, string $filename): void
	{
		nocache_headers();
		header('Content-Type: ' . $mime);
		header('Content-Disposition: attachment; filename="' . self::safe_filename($filename) . '"');
		header('X-Content-Type-Options: nosniff');
	}

	private static function safe_filename(string $name): string
	{
		$name = preg_replace('/[^\w.\-]+/', '_', $name) ?? 'export';

		return $name !== '' ? $name : 'export';
	}

	private static function safe_sheet_name(string $name, int $index): string
	{
		$name = trim($name);
		if ($name === '') {
			$name = 'Sheet' . $index;
		}
		$name = str_replace(['\\', '/', '*', '?', ':', '[', ']'], '', $name);
		if (function_exists('mb_substr')) {
			$name = mb_substr($name, 0, 31);
		} else {
			$name = substr($name, 0, 31);
		}

		return $name !== '' ? $name : ('Sheet' . $index);
	}

	/**
	 * @param list<string>                  $headers
	 * @param list<list<string|int|float>> $rows
	 */
	private static function worksheet_xml(array $headers, array $rows): string
	{
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
		$xml .= '<sheetData>';

		$r = 1;
		if ($headers !== []) {
			$xml .= '<row r="' . $r . '">';
			$col = 0;
			foreach ($headers as $h) {
				$xml .= self::inline_cell(self::col_letter($col) . $r, (string) $h);
				$col++;
			}
			$xml .= '</row>';
			$r++;
		}

		foreach ($rows as $row) {
			$xml .= '<row r="' . $r . '">';
			$col = 0;
			foreach ($row as $cell) {
				$xml .= self::inline_cell(self::col_letter($col) . $r, (string) $cell);
				$col++;
			}
			$xml .= '</row>';
			$r++;
		}

		$xml .= '</sheetData></worksheet>';

		return $xml;
	}

	private static function inline_cell(string $ref, string $value): string
	{
		$value = self::xml_escape($value);

		return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $value . '</t></is></c>';
	}

	private static function col_letter(int $index): string
	{
		$index = max(0, $index);
		$letters = '';
		do {
			$letters = chr(65 + ($index % 26)) . $letters;
			$index   = intdiv($index, 26) - 1;
		} while ($index >= 0);

		return $letters;
	}

	private static function xml_escape(string $value): string
	{
		return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}

	private static function content_types_xml(int $sheet_count): string
	{
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
		$xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
		$xml .= '<Default Extension="xml" ContentType="application/xml"/>';
		$xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
		$xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
		for ($i = 1; $i <= $sheet_count; $i++) {
			$xml .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		$xml .= '</Types>';

		return $xml;
	}

	private static function rels_root_xml(): string
	{
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	/**
	 * @param list<array{name: string, path: string, id: int}> $sheets
	 */
	private static function workbook_xml(array $sheets): string
	{
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
		$xml .= '<sheets>';
		foreach ($sheets as $sheet) {
			$xml .= '<sheet name="' . self::xml_escape($sheet['name']) . '" sheetId="' . (int) $sheet['id'] . '" r:id="rId' . (int) $sheet['id'] . '"/>';
		}
		$xml .= '</sheets></workbook>';

		return $xml;
	}

	private static function workbook_rels_xml(int $sheet_count): string
	{
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		for ($i = 1; $i <= $sheet_count; $i++) {
			$xml .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
		}
		$xml .= '<Relationship Id="rId' . ($sheet_count + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
		$xml .= '</Relationships>';

		return $xml;
	}

	private static function styles_xml(): string
	{
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
			. '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
			. '<borders count="1"><border/></borders>'
			. '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
			. '<cellXfs count="1"><xf/></cellXfs>'
			. '</styleSheet>';
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private static function build_report_pdf(array $document): string
	{
		$page_w = 595.28;
		$page_h = 841.89;
		$ml     = 36.0;
		$mr     = 36.0;
		$mb     = 42.0;
		$content_top = $page_h - 92;
		$content_w   = $page_w - $ml - $mr;

		$title    = (string) ($document['title'] ?? 'Lumen');
		$subtitle = (string) ($document['subtitle'] ?? '');
		$meta     = is_array($document['meta'] ?? null) ? $document['meta'] : [];
		$kpis     = is_array($document['kpis'] ?? null) ? $document['kpis'] : [];
		$sections = is_array($document['sections'] ?? null) ? $document['sections'] : [];
		$stamp    = wp_date('Y-m-d H:i');

		$pages = [];
		$buf   = '';
		$y     = $content_top;
		$page_index = 0;

		$flush = static function () use (&$pages, &$buf, &$page_index, $title, $subtitle, $stamp, $page_w, $ml, $mr, $mb): void {
			if ($buf === '' && $pages !== []) {
				return;
			}
			$page_index++;
			$header = self::pdf_draw_header($title, $subtitle, $stamp, $page_w);
			$footer = self::pdf_draw_footer($page_index, $page_w, $ml, $mr, $mb);
			$pages[] = $header . $buf . $footer;
			$buf     = '';
		};

		$ensure = static function (float $need) use (&$buf, &$y, &$flush, $content_top, $mb): void {
			if ($y - $need < $mb + 8) {
				$flush();
				$y = $content_top;
			}
		};

		// First page header space already reserved via content_top; draw header on flush.
		// Bootstrap empty page buffer.
		$y = $content_top;

		if ($meta !== []) {
			$ensure(28);
			$buf .= self::pdf_draw_meta_row($meta, $ml, $y, $content_w);
			$y   -= 26;
		}

		if ($kpis !== []) {
			$ensure(58);
			$buf .= self::pdf_draw_kpis($kpis, $ml, $y, $content_w);
			$y   -= 62;
		}

		foreach ($sections as $section) {
			if (! is_array($section)) {
				continue;
			}
			$sec_title = (string) ($section['title'] ?? '');
			$type      = (string) ($section['type'] ?? 'kv');

			$ensure(30);
			$buf .= self::pdf_draw_section_title($sec_title, $ml, $y, $content_w);
			$y   -= 22;

			if ($type === 'table') {
				$headers = is_array($section['headers'] ?? null) ? $section['headers'] : [];
				$rows    = is_array($section['rows'] ?? null) ? $section['rows'] : [];
				$col_w   = self::pdf_col_widths(count($headers), $content_w);

				$ensure(18);
				$buf .= self::pdf_draw_table_header($headers, $col_w, $ml, $y);
				$y   -= 18;

				$i = 0;
				foreach ($rows as $row) {
					if (! is_array($row)) {
						continue;
					}
					$cells  = array_map(static fn ($v) => (string) $v, $row);
					$height = self::pdf_table_row_height($cells, $col_w);
					$ensure($height + 2);
					$buf .= self::pdf_draw_table_row($cells, $col_w, $ml, $y, $height, $i % 2 === 1);
					$y   -= $height;
					$i++;
				}
				$y -= 10;
				continue;
			}

			if ($type === 'list') {
				$items = is_array($section['items'] ?? null) ? $section['items'] : [];
				if ($items === []) {
					$ensure(14);
					$buf .= self::pdf_text($ml, $y, 9, self::pdf_rgb(0.45, 0.44, 0.42), 'Aucune entrée.', false);
					$y   -= 20;
					continue;
				}
				foreach ($items as $item) {
					$wrapped = self::pdf_wrap((string) $item, 92);
					$block_h = max(14.0, count($wrapped) * 12.0 + 6);
					$ensure($block_h);
					$buf .= self::pdf_draw_list_item($wrapped, $ml, $y, $content_w, $block_h);
					$y   -= $block_h + 4;
				}
				$y -= 6;
				continue;
			}

			// kv
			$pairs = is_array($section['pairs'] ?? null) ? $section['pairs'] : [];
			if ($pairs === []) {
				$ensure(14);
				$buf .= self::pdf_text($ml, $y, 9, self::pdf_rgb(0.45, 0.44, 0.42), 'Aucune donnée.', false);
				$y   -= 20;
				continue;
			}
			$i = 0;
			foreach ($pairs as $pair) {
				if (! is_array($pair)) {
					continue;
				}
				$values = array_values($pair);
				$k      = (string) ($values[0] ?? '');
				$v      = (string) ($values[1] ?? '');
				$ensure(16);
				$buf .= self::pdf_draw_kv_row($k, $v, $ml, $y, $content_w, $i % 2 === 1);
				$y   -= 16;
				$i++;
			}
			$y -= 10;
		}

		$flush();
		if ($pages === []) {
			$pages[] = self::pdf_draw_header($title, $subtitle, $stamp, $page_w)
				. self::pdf_draw_footer(1, $page_w, $ml, $mr, $mb);
		}

		// Inject total page count into footers.
		$total = count($pages);
		foreach ($pages as $idx => $content) {
			$pages[$idx] = str_replace('{{PAGE}}', (string) ($idx + 1), $content);
			$pages[$idx] = str_replace('{{PAGES}}', (string) $total, $pages[$idx]);
		}

		return self::pdf_assemble($pages, $page_w, $page_h);
	}

	private static function pdf_draw_header(string $title, string $subtitle, string $stamp, float $page_w): string
	{
		$page_h = 841.89;
		$out    = '';
		// Magenta band (top).
		$out .= sprintf("0.753 0.149 0.827 rg\n0 %.2F %.2F 44 re f\n", $page_h - 44, $page_w);
		// Soft title panel.
		$out .= sprintf("0.961 0.961 0.957 rg\n0 %.2F %.2F 46 re f\n", $page_h - 90, $page_w);
		$out .= self::pdf_text(36, $page_h - 28, 9, '1 1 1', 'LUMEN', true);
		$out .= self::pdf_text(90, $page_h - 28, 9, '1 1 1', $stamp, false);
		$out .= self::pdf_text(36, $page_h - 64, 16, self::pdf_rgb(0.11, 0.10, 0.09), $title, true);
		if ($subtitle !== '') {
			$out .= self::pdf_text(36, $page_h - 80, 9, self::pdf_rgb(0.34, 0.33, 0.32), $subtitle, false);
		}
		// Accent line.
		$out .= sprintf("0.753 0.149 0.827 rg\n36 %.2F 140 2.2 re f\n", $page_h - 90);

		return $out;
	}

	private static function pdf_draw_footer(int $page_index, float $page_w, float $ml, float $mr, float $mb): string
	{
		$y    = 24;
		$out  = "0.90 0.89 0.88 rg\n{$ml} 34 " . ($page_w - $ml - $mr) . " 0.6 re f\n";
		$out .= self::pdf_text($ml, $y, 8, self::pdf_rgb(0.45, 0.44, 0.42), 'Lumen — médias, SEO et optimisation', false);
		$out .= self::pdf_text($page_w - $mr - 70, $y, 8, self::pdf_rgb(0.45, 0.44, 0.42), 'Page {{PAGE}} / {{PAGES}}', false);
		unset($page_index, $mb);

		return $out;
	}

	/**
	 * @param list<array{label?: string, value?: string}> $meta
	 */
	private static function pdf_draw_meta_row(array $meta, float $x, float $y, float $w): string
	{
		$out = "0.961 0.961 0.957 rg\n{$x} " . ($y - 14) . " {$w} 20 re f\n";
		$n   = max(1, count($meta));
		$col = $w / $n;
		$i   = 0;
		foreach ($meta as $item) {
			if (! is_array($item)) {
				continue;
			}
			$label = (string) ($item['label'] ?? '');
			$value = (string) ($item['value'] ?? '');
			$cx    = $x + ($i * $col) + 8;
			$out  .= self::pdf_text($cx, $y + 2, 7, self::pdf_rgb(0.45, 0.44, 0.42), self::pdf_upper($label), true);
			$out  .= self::pdf_text($cx, $y - 10, 9, self::pdf_rgb(0.11, 0.10, 0.09), $value, false);
			$i++;
		}

		return $out;
	}

	/**
	 * @param list<array{label?: string, value?: string, tone?: string}> $kpis
	 */
	private static function pdf_draw_kpis(array $kpis, float $x, float $y, float $w): string
	{
		$kpis = array_values(array_filter($kpis, static fn ($k) => is_array($k)));
		$n    = max(1, min(4, count($kpis)));
		$gap  = 8.0;
		$col  = ($w - ($gap * ($n - 1))) / $n;
		$out  = '';
		for ($i = 0; $i < $n; $i++) {
			$item  = $kpis[$i];
			$label = (string) ($item['label'] ?? '');
			$value = (string) ($item['value'] ?? '');
			$tone  = (string) ($item['tone'] ?? 'neutral');
			$cx    = $x + ($i * ($col + $gap));
			$out  .= "0.98 0.98 0.975 rg\n{$cx} " . ($y - 44) . " {$col} 50 re f\n";
			$accent = $tone === 'ok'
				? self::pdf_rgb(0.02, 0.47, 0.34)
				: ($tone === 'error'
					? self::pdf_rgb(0.75, 0.07, 0.24)
					: ($tone === 'warn'
						? self::pdf_rgb(0.71, 0.33, 0.04)
						: self::pdf_rgb(0.75, 0.15, 0.83)));
			$out .= "{$accent} rg\n{$cx} " . ($y - 44) . " 3 50 re f\n";
			$out .= self::pdf_text($cx + 10, $y - 8, 8, self::pdf_rgb(0.45, 0.44, 0.42), $label, false);
			$out .= self::pdf_text($cx + 10, $y - 30, 18, self::pdf_rgb(0.11, 0.10, 0.09), $value, true);
		}

		return $out;
	}

	private static function pdf_draw_section_title(string $title, float $x, float $y, float $w): string
	{
		$out  = self::pdf_text($x, $y, 11, self::pdf_rgb(0.11, 0.10, 0.09), $title, true);
		$out .= "0.753 0.149 0.827 rg\n{$x} " . ($y - 6) . " 48 1.5 re f\n";
		unset($w);

		return $out;
	}

	/**
	 * @param list<string> $headers
	 * @param list<float>  $col_w
	 */
	private static function pdf_draw_table_header(array $headers, array $col_w, float $x, float $y): string
	{
		$total = array_sum($col_w);
		$out   = "0.753 0.149 0.827 rg\n{$x} " . ($y - 12) . " {$total} 16 re f\n";
		$cx    = $x + 4;
		foreach ($headers as $i => $h) {
			$out .= self::pdf_text($cx, $y - 8, 8, '1 1 1', (string) $h, true);
			$cx  += $col_w[$i] ?? 40;
		}

		return $out;
	}

	/**
	 * @param list<string> $cells
	 * @param list<float>  $col_w
	 */
	private static function pdf_draw_table_row(array $cells, array $col_w, float $x, float $y, float $height, bool $alt): string
	{
		$total = array_sum($col_w);
		$out   = '';
		if ($alt) {
			$out .= "0.961 0.961 0.957 rg\n{$x} " . ($y - $height + 2) . " {$total} {$height} re f\n";
		}
		$cx = $x + 4;
		foreach ($cells as $i => $cell) {
			$width = ($col_w[$i] ?? 40) - 6;
			$lines = self::pdf_wrap_to_width((string) $cell, $width, 8);
			$ty    = $y - 10;
			foreach ($lines as $line) {
				$out .= self::pdf_text($cx, $ty, 8, self::pdf_rgb(0.20, 0.19, 0.18), $line, false);
				$ty  -= 10;
			}
			$cx += $col_w[$i] ?? 40;
		}

		return $out;
	}

	/**
	 * @param list<string> $cells
	 * @param list<float>  $col_w
	 */
	private static function pdf_table_row_height(array $cells, array $col_w): float
	{
		$max = 1;
		foreach ($cells as $i => $cell) {
			$width = ($col_w[$i] ?? 40) - 6;
			$max   = max($max, count(self::pdf_wrap_to_width((string) $cell, $width, 8)));
		}

		return max(16.0, ($max * 10.0) + 6.0);
	}

	/**
	 * @return list<float>
	 */
	private static function pdf_col_widths(int $cols, float $total): array
	{
		$cols = max(1, $cols);
		if ($cols === 2) {
			return [$total * 0.38, $total * 0.62];
		}
		if ($cols === 3) {
			return [$total * 0.18, $total * 0.42, $total * 0.40];
		}
		if ($cols === 4) {
			return [$total * 0.12, $total * 0.28, $total * 0.20, $total * 0.40];
		}
		$w = $total / $cols;
		return array_fill(0, $cols, $w);
	}

	private static function pdf_draw_kv_row(string $key, string $value, float $x, float $y, float $w, bool $alt): string
	{
		$out = '';
		if ($alt) {
			$out .= "0.961 0.961 0.957 rg\n{$x} " . ($y - 12) . " {$w} 15 re f\n";
		}
		$out .= self::pdf_text($x + 6, $y - 8, 9, self::pdf_rgb(0.34, 0.33, 0.32), $key, false);
		$out .= self::pdf_text($x + ($w * 0.42), $y - 8, 9, self::pdf_rgb(0.11, 0.10, 0.09), $value, true);

		return $out;
	}

	/**
	 * @param list<string> $lines
	 */
	private static function pdf_draw_list_item(array $lines, float $x, float $y, float $w, float $h): string
	{
		$out  = "0.98 0.98 0.975 rg\n{$x} " . ($y - $h + 4) . " {$w} {$h} re f\n";
		$out .= "0.753 0.149 0.827 rg\n{$x} " . ($y - $h + 4) . " 2.5 {$h} re f\n";
		$ty   = $y - 8;
		foreach ($lines as $line) {
			$out .= self::pdf_text($x + 10, $ty, 8.5, self::pdf_rgb(0.20, 0.19, 0.18), $line, false);
			$ty  -= 11;
		}

		return $out;
	}

	private static function pdf_text(float $x, float $y, float $size, string $rgb, string $text, bool $bold): string
	{
		$font = $bold ? '/F2' : '/F1';

		return "BT {$font} {$size} Tf {$rgb} rg " . sprintf('%.2F %.2F Td', $x, $y)
			. ' (' . self::pdf_escape($text) . ") Tj ET\n";
	}

	private static function pdf_rgb(float $r, float $g, float $b): string
	{
		return sprintf('%.3F %.3F %.3F', $r, $g, $b);
	}

	/**
	 * @param list<string> $pages
	 */
	private static function pdf_assemble(array $pages, float $page_w, float $page_h): string
	{
		$objects = [];
		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$obj_id = 3;
		$kids   = [];
		$page_objs = [];

		foreach ($pages as $content) {
			$content_id = $obj_id++;
			$page_id    = $obj_id++;
			$objects[$content_id] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
			$page_objs[] = ['page' => $page_id, 'content' => $content_id];
			$kids[]      = $page_id . ' 0 R';
		}

		$font_reg  = $obj_id++;
		$font_bold = $obj_id++;
		// WinAnsiEncoding required for French accents (é, è, à…).
		$objects[$font_reg]  = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[$font_bold] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

		foreach ($page_objs as $po) {
			$objects[$po['page']] = sprintf(
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>',
				$page_w,
				$page_h,
				$font_reg,
				$font_bold,
				$po['content']
			);
		}

		$objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';

		ksort($objects);
		$pdf = "%PDF-1.4\n";
		$offsets = [0];
		foreach ($objects as $id => $body) {
			$offsets[$id] = strlen($pdf);
			$pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref_pos = strlen($pdf);
		$max_id   = max(array_keys($objects));
		$pdf     .= "xref\n0 " . ($max_id + 1) . "\n";
		$pdf     .= "0000000000 65535 f \n";
		for ($i = 1; $i <= $max_id; $i++) {
			$pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
		}
		$pdf .= "trailer\n<< /Size " . ($max_id + 1) . " /Root 1 0 R >>\n";
		$pdf .= "startxref\n" . $xref_pos . "\n%%EOF";

		return $pdf;
	}

	private static function pdf_upper(string $text): string
	{
		if (function_exists('mb_strtoupper')) {
			return mb_strtoupper($text, 'UTF-8');
		}

		return strtoupper($text);
	}

	/**
	 * Convert UTF-8 → Windows-1252 (WinAnsi) for core PDF fonts.
	 */
	private static function pdf_to_winansi(string $text): string
	{
		if ($text === '') {
			return '';
		}

		if (function_exists('mb_convert_encoding')) {
			$converted = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
			if (is_string($converted) && $converted !== '') {
				return $converted;
			}
		}

		if (function_exists('iconv')) {
			$converted = @iconv('UTF-8', 'Windows-1252//IGNORE', $text);
			if (is_string($converted)) {
				return $converted;
			}
		}

		// Fallback map for common French characters.
		$map = [
			'À' => "\xC0", 'Á' => "\xC1", 'Â' => "\xC2", 'Ã' => "\xC3", 'Ä' => "\xC4", 'Å' => "\xC5",
			'Æ' => "\xC6", 'Ç' => "\xC7", 'È' => "\xC8", 'É' => "\xC9", 'Ê' => "\xCA", 'Ë' => "\xCB",
			'Ì' => "\xCC", 'Í' => "\xCD", 'Î' => "\xCE", 'Ï' => "\xCF", 'Ñ' => "\xD1",
			'Ò' => "\xD2", 'Ó' => "\xD3", 'Ô' => "\xD4", 'Õ' => "\xD5", 'Ö' => "\xD6",
			'Ù' => "\xD9", 'Ú' => "\xDA", 'Û' => "\xDB", 'Ü' => "\xDC", 'Ý' => "\xDD",
			'à' => "\xE0", 'á' => "\xE1", 'â' => "\xE2", 'ã' => "\xE3", 'ä' => "\xE4", 'å' => "\xE5",
			'æ' => "\xE6", 'ç' => "\xE7", 'è' => "\xE8", 'é' => "\xE9", 'ê' => "\xEA", 'ë' => "\xEB",
			'ì' => "\xEC", 'í' => "\xED", 'î' => "\xEE", 'ï' => "\xEF", 'ñ' => "\xF1",
			'ò' => "\xF2", 'ó' => "\xF3", 'ô' => "\xF4", 'õ' => "\xF5", 'ö' => "\xF6",
			'ù' => "\xF9", 'ú' => "\xFA", 'û' => "\xFB", 'ü' => "\xFC", 'ý' => "\xFD", 'ÿ' => "\xFF",
			'œ' => 'oe', 'Œ' => 'OE', '€' => 'EUR', '’' => "'", '‘' => "'", '“' => '"', '”' => '"',
			'—' => '-', '–' => '-', '…' => '...', '«' => '"', '»' => '"',
		];

		return strtr($text, $map);
	}

	private static function pdf_escape(string $text): string
	{
		$text = self::pdf_to_winansi($text);

		return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
	}

	private static function pdf_strlen(string $text): int
	{
		return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
	}

	private static function pdf_substr(string $text, int $start, ?int $length = null): string
	{
		if (function_exists('mb_substr')) {
			return $length === null
				? mb_substr($text, $start, null, 'UTF-8')
				: mb_substr($text, $start, $length, 'UTF-8');
		}

		return $length === null ? substr($text, $start) : substr($text, $start, $length);
	}

	/**
	 * @return list<string>
	 */
	private static function pdf_wrap(string $text, int $width): array
	{
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		$out  = [];
		foreach (explode("\n", $text) as $paragraph) {
			if ($paragraph === '') {
				$out[] = '';
				continue;
			}
			while (self::pdf_strlen($paragraph) > $width) {
				$slice = self::pdf_substr($paragraph, 0, $width);
				$pos   = function_exists('mb_strrpos')
					? mb_strrpos($slice, ' ', 0, 'UTF-8')
					: strrpos($slice, ' ');
				if ($pos === false || $pos < 20) {
					$pos = $width;
				}
				$out[]     = self::pdf_substr($paragraph, 0, (int) $pos);
				$paragraph = ltrim(self::pdf_substr($paragraph, (int) $pos));
			}
			$out[] = $paragraph;
		}

		return $out;
	}

	/**
	 * Approximate wrap using average glyph width for Helvetica.
	 *
	 * @return list<string>
	 */
	private static function pdf_wrap_to_width(string $text, float $width_pt, float $font_size): array
	{
		$avg   = $font_size * 0.5;
		$chars = max(8, (int) floor($width_pt / max(1.0, $avg)));

		return self::pdf_wrap($text, $chars);
	}
}
