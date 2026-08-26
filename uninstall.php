<?php
/**
 * Uninstall Lumen WP — removes options, jobs table, and attachment meta `_lumen_*`.
 * Media files on disk are left intact.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

// --- Options ---
$options = [
	'lumen_wp_settings',
	'lumen_wp_icons',
	'lumen_wp_bulk_job',
	'lumen_wp_bulk_history',
	'lumen_wp_urls_job',
	'lumen_wp_urls_last_errors',
	'lumen_wp_ai_usage',
	'lumen_wp_db_version',
	'lumen_wp_keys_migrated',
	'lumen_wp_llms_txt',
	'lumen_wp_llms_txt_enabled',
	'lumen_wp_llms_txt_updated_at',
	'lumen_wp_last_audit',
];

foreach ($options as $option) {
	delete_option($option);
}

// --- Transients ---
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE '_transient_lumen_wp_%'
	   OR option_name LIKE '_transient_timeout_lumen_wp_%'
	   OR option_name LIKE '_site_transient_lumen_wp_%'
	   OR option_name LIKE '_site_transient_timeout_lumen_wp_%'"
);

// --- Attachment meta (incl. stuck « processing ») ---
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like('_lumen_') . '%'
	)
);

// --- Custom table ---
$table = $wpdb->prefix . 'lumen_jobs';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query("DROP TABLE IF EXISTS {$table}");
