<?php

declare(strict_types=1);

namespace Defyn\Dashboard;

/**
 * Singleton bootstrap. Wires up activation hooks now;
 * additional services (REST controllers, Action Scheduler jobs, etc.) added in later F-phases.
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
        register_activation_hook(DEFYN_DASHBOARD_FILE, [Activation::class, 'activate']);
    }
}
