<?php
/**
 * Plugin Name:       Lumen
 * Plugin URI:        https://github.com/RomainDoyen/WP-Lumen
 * Description:       Optimise et enrichit les médias de la bibliothèque (WebP/AVIF/JPEG, SEO, SVG / PDF / vidéos). Multi-IA Vision + traitement en arrière-plan.
 * Version:           1.4.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Lumen
 * Author URI:        https://github.com/RomainDoyen/WP-Lumen
 * License:           UNLICENSED
 * Text Domain:       lumen-wp
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('LUMEN_WP_VERSION', '1.4.0');
define('LUMEN_WP_FILE', __FILE__);
define('LUMEN_WP_PATH', plugin_dir_path(__FILE__));
define('LUMEN_WP_URL', plugin_dir_url(__FILE__));
define('LUMEN_WP_BASENAME', plugin_basename(__FILE__));

require_once LUMEN_WP_PATH . 'includes/Plugin.php';
require_once LUMEN_WP_PATH . 'includes/As_Bridge.php';

// Action Scheduler must register before plugins_loaded priority 0.
\LumenWp\As_Bridge::bootstrap();

\LumenWp\Plugin::instance()->init();
