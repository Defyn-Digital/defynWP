<?php
/**
 * Test bootstrap for DefynWP Connector — mirrors dashboard-plugin's pattern.
 * Loads wp-phpunit's harness, then loads the connector plugin as a muplugin
 * so its activation hook can run before tests.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$config_path = __DIR__ . '/../wp-tests-config.php';
if (!file_exists($config_path)) {
    fwrite(STDERR, "Missing " . $config_path . " — copy tests/wp-tests-config.php.example and fill in DB creds.\n");
    exit(1);
}
putenv('WP_PHPUNIT__TESTS_CONFIG=' . $config_path);

$wp_tests_dir = __DIR__ . '/../vendor/wp-phpunit/wp-phpunit';
if (!file_exists($wp_tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, "wp-phpunit not installed. Run: composer install\n");
    exit(1);
}

require_once $wp_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require __DIR__ . '/../defyn-connector.php';
});

require $wp_tests_dir . '/includes/bootstrap.php';
