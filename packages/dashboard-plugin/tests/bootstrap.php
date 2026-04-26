<?php
/**
 * Test bootstrap.
 *
 * Loads wp-phpunit's WordPress test harness. wp-phpunit ships its own copy of WP core
 * inside vendor/wp-phpunit/wp-phpunit/, but it needs:
 *   1. A WP_PHPUNIT__TESTS_CONFIG file (database creds for the test DB)
 *   2. Our plugin loaded as a "muplugin" so it activates before tests run
 */

declare(strict_types=1);

// Polyfills for PHPUnit version differences.
require_once __DIR__ . '/../vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// Path to wp-phpunit's bootstrap.
$wp_tests_dir = __DIR__ . '/../vendor/wp-phpunit/wp-phpunit';
if (!file_exists($wp_tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, "wp-phpunit not installed. Run: composer install\n");
    exit(1);
}

require_once $wp_tests_dir . '/includes/functions.php';

// Load our plugin before WP test setup runs activation hooks.
tests_add_filter('muplugins_loaded', static function (): void {
    require __DIR__ . '/../defyn-dashboard.php';
});

// Start the WP test environment.
require $wp_tests_dir . '/includes/bootstrap.php';
