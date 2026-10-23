<?php

declare(strict_types=1);

namespace LumenWp\Tests;

use LumenWp\Admin\Brand;
use WP_UnitTestCase;

/**
 * @covers \LumenWp\Admin\Brand
 */
class BrandIconTest extends WP_UnitTestCase
{
	private function capture(callable $render): string
	{
		ob_start();
		$render();
		return (string) ob_get_clean();
	}

	public function test_icon_known_name_renders_svg(): void
	{
		$html = $this->capture(static function (): void {
			Brand::icon('dashboard');
		});

		$this->assertStringContainsString('lumen-wp-icon--dashboard', $html);
		$this->assertStringContainsString('<svg', $html);
		$this->assertStringContainsString('aria-hidden="true"', $html);
	}

	public function test_icon_unknown_name_renders_nothing(): void
	{
		$html = $this->capture(static function (): void {
			Brand::icon('nope');
		});

		$this->assertSame('', $html);
	}

	public function test_scene_known_name_renders_svg(): void
	{
		$html = $this->capture(static function (): void {
			Brand::scene('history');
		});

		$this->assertStringContainsString('lumen-wp-scene--history', $html);
		$this->assertStringContainsString('<svg', $html);
	}

	public function test_scene_unknown_name_renders_nothing(): void
	{
		$html = $this->capture(static function (): void {
			Brand::scene('nope');
		});

		$this->assertSame('', $html);
	}

	public function test_nav_renders_an_icon_per_item(): void
	{
		$user = self::factory()->user->create(['role' => 'administrator']);
		wp_set_current_user($user);

		$html = $this->capture(static function (): void {
			Brand::render_nav('dashboard');
		});

		$this->assertStringContainsString('lumen-wp-icon--dashboard', $html);
		$this->assertStringContainsString('lumen-wp-icon--bulk', $html);
		$this->assertStringContainsString('lumen-wp-icon--history', $html);
		$this->assertStringContainsString('lumen-wp-icon--audit', $html);
		$this->assertStringContainsString('lumen-wp-icon--tools', $html);
		$this->assertStringContainsString('lumen-wp-icon--icons', $html);
		$this->assertStringContainsString('lumen-wp-icon--settings', $html);
	}
}
