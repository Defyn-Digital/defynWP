<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Auth\GoogleConfig;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /defyn/v1/auth/config
 *
 * Public, no-auth endpoint. Serves the SPA the non-secret config it needs
 * BEFORE login — currently just the Google OAuth Client ID resolved by
 * GoogleConfig (env constant first, wp-admin option fallback). Returns an empty
 * string when Google sign-in is not configured, so the SPA can hide the button.
 *
 * Success (200): { google_client_id: string, error: null }
 */
final class AuthConfigController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'google_client_id' => GoogleConfig::clientId(),
            'error'            => null,
        ], 200);
    }
}
