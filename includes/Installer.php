<?php

declare(strict_types=1);

namespace LumenWp;

final class Installer
{
	public const OPTION = 'lumen_wp_db_version';
	public const SCHEMA_VERSION = '1.7.0';

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
		update_option(self::OPTION, self::SCHEMA_VERSION, false);
	}

	public static function maybe_upgrade(): void
	{
		$current = (string) get_option(self::OPTION, '');
		if ($current === self::SCHEMA_VERSION) {
			return;
		}
		self::install();
	}
}
