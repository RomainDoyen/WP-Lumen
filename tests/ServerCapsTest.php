<?php

declare(strict_types=1);

namespace LumenWp\Tests;

use LumenWp\Server_Caps;
use WP_UnitTestCase;

/**
 * @covers \LumenWp\Server_Caps
 */
class ServerCapsTest extends WP_UnitTestCase
{
	public function test_detect_returns_all_keys(): void
	{
		$caps = Server_Caps::detect(true);

		$expected = [
			'imagick',
			'gd',
			'webp',
			'avif',
			'ghostscript',
			'ffmpeg',
			'exec',
			'shell_exec',
			'openssl',
			'action_scheduler',
			'memory_limit',
			'memory_bytes',
		];

		foreach ($expected as $key) {
			$this->assertArrayHasKey($key, $caps, "Missing capability key: {$key}");
		}
	}

	public function test_detect_caches_and_bypass(): void
	{
		$a = Server_Caps::detect();
		$b = Server_Caps::detect(true);

		$this->assertIsArray($a);
		$this->assertIsArray($b);
		$this->assertSame(array_keys($a), array_keys($b));
	}

	public function test_memory_bytes_is_positive(): void
	{
		$caps = Server_Caps::detect(true);
		$this->assertGreaterThan(0, $caps['memory_bytes']);
	}

	public function test_flush_clears_cache(): void
	{
		Server_Caps::detect();
		Server_Caps::flush();

		// After flush, detect should re-detect.
		$caps = Server_Caps::detect(true);
		$this->assertIsArray($caps);
	}
}
