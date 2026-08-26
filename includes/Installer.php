<?php

declare(strict_types=1);

namespace LumenWp;

final class Installer
{
	public const OPTION = 'lumen_wp_db_version';
	/** Bump to run one-shot meta repairs (clear stuck processing, etc.). */
	public const SCHEMA_VERSION = '1.9.6';

	public static function install(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = Job_Repository::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			type varchar(32) NOT NULL DEFAULT 'process',
			status varchar(32) NOT NULL DEFAULT 'ok',
			provider_used varchar(64) DEFAULT NULL,
			tokens_prompt int unsigned DEFAULT NULL,
			tokens_completion int unsigned DEFAULT NULL,
			tokens_total int unsigned DEFAULT NULL,
			tokens_source varchar(16) DEFAULT NULL,
			error_message text DEFAULT NULL,
			created_at datetime NOT NULL,
			completed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta($sql);

		$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		if ($exists !== $table) {
			error_log('[Lumen] install failed: table ' . $table . ' missing after dbDelta');
			return;
		}

		update_option(self::OPTION, self::SCHEMA_VERSION, false);
		self::clear_stuck_processing_meta();
	}

	public static function maybe_upgrade(): void
	{
		$current = (string) get_option(self::OPTION, '');
		if ($current === self::SCHEMA_VERSION) {
			return;
		}
		self::install();
	}

	/**
	 * Clear every attachment stuck in « processing » (survives crash / incomplete uninstall).
	 */
	public static function clear_stuck_processing_meta(): int
	{
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = %s AND meta_value = 'processing'
				LIMIT 500",
				'_lumen_status'
			)
		);
		// phpcs:enable

		$fixed = 0;
		foreach ($ids as $raw_id) {
			$id = (int) $raw_id;
			if ($id <= 0) {
				continue;
			}
			delete_post_meta($id, '_lumen_status');
			delete_post_meta($id, '_lumen_processing_at');
			update_post_meta($id, '_lumen_error', __('Statut « processing » réparé — média remis en file.', 'lumen-wp'));
			++$fixed;
		}

		return $fixed;
	}
}
