<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest\Middleware;

use Defyn\Dashboard\Auth\Exceptions\InvalidTokenException;
use Defyn\Dashboard\Auth\TokenService;
use WP_Error;
use WP_REST_Request;

/**
 * Validates the Authorization: Bearer <jwt> header.
 *
 * Used as a permission_callback in route registration. On success, returns true
 * AND stashes the user_id on the request via $request->set_param('_authenticated_user_id', $id)
 * so the controller can read it. On failure, returns a WP_Error — WP_REST_Server
 * inspects this and short-circuits dispatch with the WP_Error's `status` data,
 * skipping the controller entirely. (Returning a WP_REST_Response here would
 * NOT short-circuit; only WP_Error or `false` does — see WP_REST_Server::dispatch.)
 */
final class RequireAuth
{
    /** @return true|WP_Error */
    public static function check(WP_REST_Request $request)
    {
        $header = $request->get_header('Authorization');
        if (!is_string($header) || !preg_match('/^Bearer\s+(.+)$/', $header, $m)) {
            return new WP_Error(
                'auth.missing_token',
                'Authorization: Bearer <token> required.',
                ['status' => 401]
            );
        }
        $token = trim($m[1]);

        try {
            $claims = (new TokenService(DEFYN_JWT_SECRET))->decode($token);
        } catch (InvalidTokenException $e) {
            return new WP_Error(
                'auth.invalid_token',
                'Token is invalid or expired.',
                ['status' => 401]
            );
        }

        if (($claims['typ'] ?? '') !== TokenService::TYPE_ACCESS) {
            return new WP_Error(
                'auth.wrong_token_type',
                'Access token required (refresh tokens are not accepted here).',
                ['status' => 401]
            );
        }

        $request->set_param('_authenticated_user_id', (int) $claims['sub']);
        return true;
    }
}
