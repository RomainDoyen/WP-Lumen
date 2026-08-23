<?php
/**
 * Uninstall Lumen WP — removes all plugin data.
 * Media files and attachment meta are left intact on purpose.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

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

// --- Transients (AI models cache, server caps, audit flash) ---
global $wpdb;

$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE '_transient_lumen_wp_%'
	   OR option_name LIKE '_transient_timeout_lumen_wp_%'
	   OR option_name LIKE '_site_transient_lumen_wp_%'
	   OR option_name LIKE '_site_transient_timeout_lumen_wp_%'"
);

// --- Custom table ---
$table = $wpdb->prefix . 'lumen_jobs';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query("DROP TABLE IF EXISTS {$table}");
