<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Auth\Exceptions\InvalidTokenException;
use Defyn\Dashboard\Auth\RefreshTokenStore;
use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /defyn/v1/auth/refresh
 *
 * Reads refresh JWT from defyn_refresh cookie. Validates JTI is in user's
 * active list. On success: revokes old JTI, issues new access + refresh
 * (with new JTI), returns 200 + new access in body + new refresh in cookie.
 */
final class AuthRefreshController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $cookie = $_COOKIE['defyn_refresh'] ?? '';
        if (!is_string($cookie) || $cookie === '') {
            return ErrorResponse::create(401, 'auth.missing_refresh', 'Refresh cookie is required.');
        }

        $tokens = new TokenService(DEFYN_JWT_SECRET);
        try {
            $claims = $tokens->decode($cookie);
        } catch (InvalidTokenException $e) {
            return ErrorResponse::create(401, 'auth.invalid_refresh', 'Refresh token is invalid or expired.');
        }

        if (($claims['typ'] ?? '') !== TokenService::TYPE_REFRESH) {
            return ErrorResponse::create(401, 'auth.wrong_token_type', 'Refresh token required.');
        }

        $userId = (int) $claims['sub'];
        $oldJti = (string) ($claims['jti'] ?? '');
        $store = new RefreshTokenStore();
        if ($oldJti === '' || !$store->isActive($userId, $oldJti)) {
            return ErrorResponse::create(401, 'auth.refresh_revoked', 'Refresh token is no longer active.');
        }

        // Rotate: revoke old JTI, issue new pair, remember new JTI.
        $store->revoke($userId, $oldJti);

        $newAccess = $tokens->issueAccess($userId);
        $newRefresh = $tokens->issueRefresh($userId);
        $newClaims = $tokens->decode($newRefresh);
        $store->remember($userId, $newClaims['jti'], (int) $newClaims['exp']);

        $response = new WP_REST_Response(['access_token' => $newAccess], 200);
        $response->header('Set-Cookie', AuthLoginController::buildRefreshCookie($newRefresh, (int) $newClaims['exp']));

        return $response;
    }
}
