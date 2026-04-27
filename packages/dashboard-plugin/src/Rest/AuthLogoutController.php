<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Auth\Exceptions\InvalidTokenException;
use Defyn\Dashboard\Auth\RefreshTokenStore;
use Defyn\Dashboard\Auth\TokenService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /defyn/v1/auth/logout
 *
 * Idempotent. Always returns 204 (even with missing/malformed cookie) —
 * we don't want to leak whether a session existed. Best-effort revokes
 * the refresh JTI and clears the cookie.
 */
final class AuthLogoutController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $cookie = $_COOKIE['defyn_refresh'] ?? '';

        if (is_string($cookie) && $cookie !== '') {
            try {
                $claims = (new TokenService(DEFYN_JWT_SECRET))->decode($cookie);
                if (($claims['typ'] ?? '') === TokenService::TYPE_REFRESH) {
                    $userId = (int) ($claims['sub'] ?? 0);
                    $jti = (string) ($claims['jti'] ?? '');
                    if ($userId > 0 && $jti !== '') {
                        (new RefreshTokenStore())->revoke($userId, $jti);
                    }
                }
            } catch (InvalidTokenException $e) {
                // Malformed cookie — nothing to revoke. Still clear it below.
            }
        }

        $response = new WP_REST_Response(null, 204);
        // Clear the cookie regardless. Past Expires forces browser to drop it.
        $response->header(
            'Set-Cookie',
            'defyn_refresh=; Path=/wp-json/defyn/v1/auth; Expires=Thu, 01 Jan 1970 00:00:00 GMT; HttpOnly; Secure; SameSite=None'
        );

        return $response;
    }
}
