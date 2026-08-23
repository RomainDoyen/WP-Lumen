<?php

declare(strict_types=1);

namespace LumenWp\Tests;

use LumenWp\Plugin;
use LumenWp\Seo;
use WP_UnitTestCase;

/**
 * @covers \LumenWp\Seo
 */
class SeoTest extends WP_UnitTestCase
{
	private Seo $seo;

	public function set_up(): void
	{
		parent::set_up();
		$this->seo = new Seo();
	}

	public function test_build_from_filename_returns_expected_keys(): void
	{
		$attachment_id = self::factory()->attachment->create_upload_object(
			dirname(__DIR__, 2) . '/assets/admin/icons/lumen-mark.svg'
		);

		$meta = $this->seo->build_from_filename($attachment_id);

		$this->assertArrayHasKey('slug', $meta);
		$this->assertArrayHasKey('title', $meta);
		$this->assertArrayHasKey('alt_text_seo', $meta);
		$this->assertArrayHasKey('alt_text_wcag', $meta);
		$this->assertArrayHasKey('alt_text_short', $meta);
		$this->assertArrayHasKey('caption', $meta);
		$this->assertArrayHasKey('description', $meta);
		$this->assertArrayHasKey('metadata_source', $meta);
		$this->assertSame('filename', $meta['metadata_source']);
	}

	public function test_slugify_basic(): void
	{
		$this->assertSame('hello-world', $this->seo->slugify('hello world'));
		$this->assertSame('hello-world', $this->seo->slugify('Hello_World'));
		$this->assertSame('mon-article', $this->seo->slugify('Mon Article !'));
	}

	public function test_slugify_special_characters(): void
	{
		$slug = $this->seo->slugify('café résumé');
		$this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
		$this->assertNotEmpty($slug);
	}

	public function test_slugify_empty_returns_default(): void
	{
		$this->assertSame('image', $this->seo->slugify(''));
		$this->assertSame('image', $this->seo->slugify('!!!'));
	}

	public function test_humanize_filename(): void
	{
		$this->assertSame('Hello World', $this->seo->humanize_filename('hello_world'));
		$this->assertSame('Mon Article', $this->seo->humanize_filename('mon-article'));
	}

	public function test_merge_seo_fields_applies_incoming(): void
	{
		$base = [
			'title'          => 'Old Title',
			'alt_text_seo'   => 'Old Alt',
			'alt_text_wcag'  => 'Old WCAG',
			'alt_text_short' => 'Old Short',
			'caption'        => 'Old Caption',
			'description'    => 'Old Desc',
		];

		$incoming = [
			'title'       => 'New Title',
			'alt_text_seo' => 'New Alt',
		];

		$result = $this->seo->merge_seo_fields($base, $incoming);

		$this->assertSame('New Title', $result['title']);
		$this->assertSame('New Alt', $result['alt_text_seo']);
		$this->assertSame('Old WCAG', $result['alt_text_wcag']);
	}

	public function test_apply_to_attachment_updates_post_title(): void
	{
		$attachment_id = self::factory()->attachment->create();

		$seo = [
			'title'          => 'SEO Title',
			'alt_text'       => 'Alt text here',
			'alt_text_seo'   => 'Alt SEO',
			'alt_text_wcag'  => 'Alt WCAG',
			'alt_text_short' => 'Alt Short',
			'caption'        => 'My caption',
			'description'    => 'My description',
		];

		$this->seo->apply_to_attachment($attachment_id, $seo);

		$post = get_post($attachment_id);
		$this->assertSame('SEO Title', $post->post_title);
		$this->assertSame('My caption', $post->post_excerpt);
		$this->assertSame('My description', $post->post_content);
		$this->assertSame('Alt text here', get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
	}

	public function test_prefix_alt_accessible_disabled_skips_wcag(): void
	{
		update_option(Plugin::OPTION_KEY, [
			'prefix_alt_accessible' => false,
		]);

		$attachment_id = self::factory()->attachment->create();
		$seo           = [
			'title'          => 'Titre',
			'alt_text_seo'   => 'Alt SEO',
			'alt_text_wcag'  => 'Alt WCAG',
			'alt_text_short' => 'Alt Short',
			'caption'        => 'Caption',
			'description'    => 'Desc',
		];

		// apply_to_attachment calls apply_to_attachment which stores via update_post_meta
		// We test via merge_seo_fields which calls apply_site_title_prefix internally
		$this->seo->apply_to_attachment($attachment_id, $seo, false);

		$stored = get_post_meta($attachment_id, Plugin::META_SEO, true);
		$this->assertIsArray($stored);

		// With prefix disabled, alt_text_wcag and alt_text_short should NOT have the site prefix
		// They should remain as-is (just truncated)
		$this->assertStringNotContainsString(get_bloginfo('name'), $stored['alt_text_wcag'] ?? '');
		$this->assertStringNotContainsString(get_bloginfo('name'), $stored['alt_text_short'] ?? '');
	}
}
