<?php

declare(strict_types=1);

namespace LumenWp\Tests;

use LumenWp\Bulk_Queue;
use LumenWp\Media_Types;
use LumenWp\Plugin;
use WP_UnitTestCase;

/**
 * @covers \LumenWp\Bulk_Queue
 */
class BulkQueueTest extends WP_UnitTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		delete_option(Bulk_Queue::OPTION);
	}

	public function test_pending_next_id_skips_processing_status(): void
	{
		$processing = $this->make_jpeg_attachment('Processing poison');
		update_post_meta($processing, Plugin::META_STATUS, 'processing');
		update_post_meta($processing, Plugin::META_PROCESSING_AT, (string) time());

		$pending = $this->make_jpeg_attachment('Really pending');

		$next = $this->invoke_next_id($processing - 1, false, [Media_Types::KIND_IMAGE]);

		$this->assertSame($pending, $next, 'Un média « processing » ne doit pas rester dans la file pending.');
	}

	public function test_pending_next_id_skips_error_status(): void
	{
		$error   = $this->make_jpeg_attachment('Failed poison');
		$pending = $this->make_jpeg_attachment('Really pending');
		update_post_meta($error, Plugin::META_STATUS, 'error');
		update_post_meta($error, Plugin::META_ERROR, 'poison');

		$next = $this->invoke_next_id(min($error, $pending) - 1, false, [Media_Types::KIND_IMAGE]);

		$this->assertSame($pending, $next);
	}

	public function test_pending_next_id_honours_skip_ids(): void
	{
		$skip    = $this->make_jpeg_attachment('Skip me');
		$pending = $this->make_jpeg_attachment('Take me');
		update_option(
			Bulk_Queue::OPTION,
			array_merge(
				Bulk_Queue::defaults(),
				['skip_ids' => [$skip]]
			),
			false
		);

		$next = $this->invoke_next_id(min($skip, $pending) - 1, false, [Media_Types::KIND_IMAGE]);

		$this->assertSame($pending, $next);
	}

	public function test_recover_stale_processing_marks_error(): void
	{
		$id = $this->make_jpeg_attachment('Stale');
		update_post_meta($id, Plugin::META_STATUS, 'processing');
		update_post_meta($id, Plugin::META_PROCESSING_AT, (string) (time() - 400));

		$fixed = Bulk_Queue::recover_stale_processing(120);

		$this->assertSame(1, $fixed);
		$this->assertSame('error', (string) get_post_meta($id, Plugin::META_STATUS, true));
	}

	public function test_should_defer_for_ai_when_budget_too_short(): void
	{
		$this->assertTrue(Bulk_Queue::should_defer_for_ai(true, true, 10.0));
		$this->assertTrue(Bulk_Queue::should_defer_for_ai(true, true, 17.99));
	}

	public function test_should_not_defer_for_ai_when_enough_budget(): void
	{
		$this->assertFalse(Bulk_Queue::should_defer_for_ai(true, true, 18.0));
		$this->assertFalse(Bulk_Queue::should_defer_for_ai(true, true, 22.0));
	}

	public function test_should_not_defer_for_ai_on_skips_or_without_ai(): void
	{
		$this->assertFalse(Bulk_Queue::should_defer_for_ai(true, false, 5.0));
		$this->assertFalse(Bulk_Queue::should_defer_for_ai(false, true, 5.0));
	}

	public function test_adapt_batch_size_stays_at_one_when_ai_enabled(): void
	{
		$queue = new Bulk_Queue();
		$ref   = new \ReflectionMethod(Bulk_Queue::class, 'adapt_batch_size');
		$ref->setAccessible(true);

		$size = $ref->invoke(
			$queue,
			[
				'use_ai'      => true,
				'batch_min'   => 1,
				'batch_max'   => 10,
				'batch_size'  => 4,
				'tick_budget' => 22,
			],
			10,
			2.0,
			false
		);

		$this->assertSame(1, $size);
	}

	private function make_jpeg_attachment(string $title): int
	{
		return (int) self::factory()->attachment->create(
			[
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $title,
				'post_status'    => 'inherit',
			]
		);
	}

	/**
	 * @param list<string> $types
	 */
	private function invoke_next_id(int $cursor, bool $force, array $types): int
	{
		$queue = new Bulk_Queue();
		$ref   = new \ReflectionMethod(Bulk_Queue::class, 'next_id');
		$ref->setAccessible(true);

		return (int) $ref->invoke($queue, $cursor, $force, $types);
	}
}
