<?php
/**
 * Uninstall Lumen WP — removes plugin options only.
 * Media files and attachment meta are left intact on purpose.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('lumen_wp_settings');
delete_option('lumen_wp_icons');
delete_option('lumen_wp_bulk_job');
delete_option('lumen_wp_bulk_history');
