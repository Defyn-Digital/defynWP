<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Auth\Exceptions\InvalidCredentialsException;
use Defyn\Dashboard\Auth\PasswordVerifier;
use Defyn\Dashboard\Auth\RefreshTokenStore;
use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /defyn/v1/auth/login
 *
 * Body: { email: string, password: string }
 *
 * Success (200): { access_token: string }  — refresh in Set-Cookie header
 * Bad creds (401): { error: { code: 'auth.invalid_credentials', message } }
 * Missing fields (400): { error: { code: 'auth.missing_fields', message } }
 */
final class AuthLoginController
{
    public static function args(): array
    {
        return [
            'email'    => ['type' => 'string', 'required' => true],
            'password' => ['type' => 'string', 'required' => true],
        ];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $email    = (string) $request->get_param('email');
        $password = (string) $request->get_param('password');

        if ($email === '' || $password === '') {
            return ErrorResponse::create(400, 'auth.missing_fields', 'Email and password are required.');
        }

        try {
            $userId = (new PasswordVerifier())->verify($email, $password);
        } catch (InvalidCredentialsException $e) {
            return ErrorResponse::create(401, 'auth.invalid_credentials', 'Invalid email or password.');
        }

        $tokens = new TokenService(DEFYN_JWT_SECRET);
        $access = $tokens->issueAccess($userId);
        $refresh = $tokens->issueRefresh($userId);
        $refreshClaims = $tokens->decode($refresh);

        // Track JTI so logout/revocation works.
        (new RefreshTokenStore())->remember($userId, $refreshClaims['jti'], (int) $refreshClaims['exp']);

        $response = new WP_REST_Response(['access_token' => $access], 200);
        self::setRefreshCookie($refresh, (int) $refreshClaims['exp']);

        return $response;
    }

    private static function setRefreshCookie(string $jwt, int $expiresAt): void
    {
        // HttpOnly + Secure + SameSite=None for cross-origin SPA. Path scoped to auth routes.
        $cookie = sprintf(
            'defyn_refresh=%s; Path=/wp-json/defyn/v1/auth; Expires=%s; HttpOnly; Secure; SameSite=None',
            $jwt,
            gmdate('D, d M Y H:i:s', $expiresAt) . ' GMT'
        );
        // headers_sent() is the proper guard in WP REST handler context.
        if (!headers_sent()) {
            header('Set-Cookie: ' . $cookie, false);
        }
    }
}
