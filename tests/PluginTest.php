<?php

declare(strict_types=1);

namespace LumenWp\Tests;

use LumenWp\Plugin;
use WP_UnitTestCase;

/**
 * @covers \LumenWp\Plugin
 */
class PluginTest extends WP_UnitTestCase
{
	public function test_instance_returns_singleton(): void
	{
		$a = Plugin::instance();
		$b = Plugin::instance();

		$this->assertSame($a, $b);
	}

	public function test_defaults_contains_required_keys(): void
	{
		$defaults = Plugin::defaults();

		$required = [
			'formats',
			'webp_quality',
			'jpeg_quality',
			'avif_quality',
			'replace_original',
			'rewrite_content_urls',
			'ai_provider',
			'ai_budget_month',
			'llms_txt_enabled',
			'emit_faq_schema',
			'prefix_alt_accessible',
			'ui_theme',
		];

		foreach ($required as $key) {
			$this->assertArrayHasKey($key, $defaults, "Missing default key: {$key}");
		}
	}

	public function test_settings_merges_defaults(): void
	{
		// Clear any stored settings.
		delete_option(Plugin::OPTION_KEY);

		$settings = Plugin::instance()->settings();

		$this->assertSame(82, $settings['webp_quality']);
		$this->assertSame(85, $settings['jpeg_quality']);
		$this->assertSame(65, $settings['avif_quality']);
		$this->assertTrue($settings['replace_original']);
		$this->assertSame('none', $settings['ai_provider']);
		$this->assertTrue($settings['prefix_alt_accessible']);
	}

	public function test_clear_settings_cache_forces_reload(): void
	{
		update_option(Plugin::OPTION_KEY, ['webp_quality' => 99]);
		Plugin::instance()->clear_settings_cache();

		$settings = Plugin::instance()->settings();
		$this->assertSame(99, $settings['webp_quality']);

		// Restore default.
		update_option(Plugin::OPTION_KEY, []);
		Plugin::instance()->clear_settings_cache();
	}

	public function test_capabilities_returns_array(): void
	{
		$caps = Plugin::capabilities();

		$this->assertArrayHasKey('imagick', $caps);
		$this->assertArrayHasKey('gd', $caps);
		$this->assertArrayHasKey('webp', $caps);
		$this->assertArrayHasKey('avif', $caps);
		$this->assertIsBool($caps['imagick']);
		$this->assertIsBool($caps['gd']);
	}

	public function test_ui_theme_default_is_light(): void
	{
		delete_option(Plugin::OPTION_KEY);
		$this->assertSame('light', Plugin::ui_theme());
	}

	public function test_ui_theme_dark_when_set(): void
	{
		update_option(Plugin::OPTION_KEY, ['ui_theme' => 'dark']);
		Plugin::instance()->clear_settings_cache();

		$this->assertSame('dark', Plugin::ui_theme());

		// Restore.
		delete_option(Plugin::OPTION_KEY);
		Plugin::instance()->clear_settings_cache();
	}

	public function test_attachment_is_done_returns_false_for_missing(): void
	{
		// Non-existent attachment.
		$this->assertFalse(Plugin::attachment_is_done(999999));
	}

	public function test_uploads_base_url_for_attachment(): void
	{
		$attachment_id = self::factory()->attachment->create_upload_object(
			dirname(__DIR__, 2) . '/assets/admin/icons/lumen-mark.svg'
		);

		$url = Plugin::uploads_base_url_for_attachment($attachment_id);
		$this->assertNotEmpty($url);
		$this->assertStringContainsString('uploads', $url);
	}
}
