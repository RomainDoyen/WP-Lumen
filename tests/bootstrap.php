<?php
/**
 * PHPUnit bootstrap for Lumen WP tests.
 *
 * Requires the WordPress test suite installed via:
 *   composer install
 *   bin/install-wp-tests.sh <db-name> <db-user> <db-pass> <db-host>
 */

declare(strict_types=1);

// Composer autoloader.
$_tests_dir = getenv('WP_TESTS_DIR');

if (! $_tests_dir) {
	$_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

if (! file_exists("{$_tests_dir}/includes/functions.php")) {
	echo "Could not find {$_tests_dir}/includes/functions.php\n";
	echo "Run: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> <db-host>\n";
	exit(1);
}

// Load the WP test functions.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Load the plugin before each test file.
 */
\Tests\Loader::register(function (): void {
	require_once dirname(__DIR__, 2) . '/lumen.php';
});

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";
