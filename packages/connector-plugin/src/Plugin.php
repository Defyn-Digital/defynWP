<?php

declare(strict_types=1);

namespace Defyn\Connector;

/**
 * Singleton bootstrap. Wires activation now;
 * REST + admin hooks added in later tasks of this plan.
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
        register_activation_hook(DEFYN_CONNECTOR_FILE, [Activation::class, 'activate']);
    }
}
