<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

/**
 * Single registration point for every REST route in the plugin.
 *
 * Plugin::boot() instantiates this and calls register() on `rest_api_init`.
 * Adding a new endpoint = adding one line to register().
 */
final class RestRouter
{
    public const NAMESPACE = 'defyn/v1';

    public function register(): void
    {
        // Auth endpoints — Tasks 6-9 add controllers and wire them in here.
    }
}
