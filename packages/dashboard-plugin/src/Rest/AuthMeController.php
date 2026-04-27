<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /defyn/v1/auth/me
 *
 * Auth: Bearer access token (handled by RequireAuth middleware).
 * Success (200): { id, email, display_name }
 */
final class AuthMeController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $user = get_userdata($userId);

        return new WP_REST_Response([
            'id'           => $userId,
            'email'        => $user->user_email,
            'display_name' => $user->display_name,
        ], 200);
    }
}
