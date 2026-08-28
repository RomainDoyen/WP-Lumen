<?php

declare(strict_types=1);

namespace LumenWp\Tests;

use LumenWp\Bulk_Queue;
use LumenWp\Installer;
use LumenWp\Job_Repository;
use LumenWp\Plugin;
use WP_UnitTestCase;

/**
 * @covers \LumenWp\Installer
 */
class InstallerTest extends WP_UnitTestCase
{
	public function test_install_creates_table(): void
	{
		global $wpdb;

		$table = Job_Repository::table();

		// Drop if exists from a previous run.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query("DROP TABLE IF EXISTS {$table}");

		Installer::install();

		$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		$this->assertSame($table, $exists);
	}

	public function test_install_sets_db_version_option(): void
	{
		Installer::install();

		$version = get_option(Installer::OPTION, '');
		$this->assertSame(Installer::SCHEMA_VERSION, $version);
	}

	public function test_maybe_upgrade_skips_when_current(): void
	{
		update_option(Installer::OPTION, Installer::SCHEMA_VERSION);

		// Should not recreate the table (no error = success).
		Installer::maybe_upgrade();

		$this->assertSame(Installer::SCHEMA_VERSION, get_option(Installer::OPTION));
	}

	public function test_maybe_upgrade_runs_when_outdated(): void
	{
		update_option(Installer::OPTION, '0.0.0');

		Installer::maybe_upgrade();

		$this->assertSame(Installer::SCHEMA_VERSION, get_option(Installer::OPTION));
	}

	public function test_job_repository_insert_and_list(): void
	{
		global $wpdb;

		// Ensure table exists.
		Installer::install();

		$attachment_id = self::factory()->attachment->create();

		$job_id = Job_Repository::insert([
			'attachment_id' => $attachment_id,
			'type'          => 'process',
			'status'        => 'ok',
		]);

		$this->assertGreaterThan(0, $job_id);

		$jobs = Job_Repository::list_by_attachment($attachment_id);
		$this->assertCount(1, $jobs);
		$this->assertSame('ok', $jobs[0]['status']);
	}

	public function test_job_repository_purge_all(): void
	{
		Installer::install();

		$attachment_id = self::factory()->attachment->create();

		Job_Repository::insert([
			'attachment_id' => $attachment_id,
			'type'          => 'process',
			'status'        => 'ok',
		]);

		$result = Job_Repository::purge_all();

		$this->assertGreaterThanOrEqual(1, $result['jobs']);

		$jobs = Job_Repository::list_by_attachment($attachment_id);
		$this->assertCount(0, $jobs);
	}

	public function test_clear_stuck_processing_marks_error_not_pending(): void
	{
		$id = self::factory()->attachment->create(
			[
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Stuck processing',
			]
		);
		update_post_meta($id, Plugin::META_STATUS, 'processing');
		update_post_meta($id, Plugin::META_PROCESSING_AT, (string) time());

		$fixed = Bulk_Queue::clear_all_processing();

		$this->assertGreaterThanOrEqual(1, $fixed);
		$this->assertSame('error', (string) get_post_meta($id, Plugin::META_STATUS, true));
		$this->assertSame('', (string) get_post_meta($id, Plugin::META_PROCESSING_AT, true));
		$this->assertNotSame('', (string) get_post_meta($id, Plugin::META_ERROR, true));
	}
}
