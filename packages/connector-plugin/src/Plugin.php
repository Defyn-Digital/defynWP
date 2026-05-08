<?php

declare(strict_types=1);

namespace Defyn\Connector;

use Defyn\Connector\Rest\RestRouter;

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

        add_action('rest_api_init', static function (): void {
            (new RestRouter())->register();
        });
    }
}
