<?php

declare(strict_types=1);

namespace Defyn\Connector;

/**
 * Singleton bootstrap. Stub created in Task 3 so phpunit's wp-phpunit
 * harness can boot without fataling on a missing Plugin class.
 *
 * Task 5 expands boot() to register the activation hook;
 * later tasks add REST + admin_menu hooks.
 */
final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function boot(): void
    {
        // Hooks registered in Task 5+.
    }
}
