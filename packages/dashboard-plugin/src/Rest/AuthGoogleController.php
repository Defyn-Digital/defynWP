<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Auth\GoogleConfig;
use Defyn\Dashboard\Auth\GoogleIdTokenVerifier;
use Defyn\Dashboard\Auth\UserProvisioner;
use Defyn\Dashboard\Auth\RefreshTokenStore;
use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Auth\Exceptions\GoogleAuthException;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /defyn/v1/auth/google
 *
 * Body: { credential: string }  — a Google-issued OIDC ID token
 *
 * Success (200): { access_token: string }  — refresh in Set-Cookie header
 * Missing credential (400): { error: { code: 'auth.google_missing_credential', message } }
 * Domain rejected (403): { error: { code: 'auth.google_domain', message } }
 * Bad token (401): { error: { code: 'auth.google_invalid', message } }
 * Not configured (503): { error: { code: 'auth.google_not_configured', message } }
 */
final class AuthGoogleController
{
    public function __construct(private ?GoogleIdTokenVerifier $verifier = null) {}

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $clientId = GoogleConfig::clientId();
        if ($clientId === '') {
            return ErrorResponse::create(503, 'auth.google_not_configured', 'Google sign-in is not configured.');
        }

        $body       = $request->get_json_params() ?: [];
        $credential = is_string($body['credential'] ?? null) ? trim((string) $body['credential']) : '';
        if ($credential === '') {
            return ErrorResponse::create(400, 'auth.google_missing_credential', 'A Google credential is required.');
        }

        $verifier = $this->verifier ?? new GoogleIdTokenVerifier($clientId);
        try {
            $claims = $verifier->verify($credential);
        } catch (GoogleAuthException $e) {
            return ErrorResponse::create($e->status, $e->errorCode, $e->getMessage());
        }

        $userId = (new UserProvisioner())->findOrCreate([
            'email' => (string) ($claims['email'] ?? ''),
            'name'  => (string) ($claims['name'] ?? ''),
            'sub'   => (string) ($claims['sub'] ?? ''),
        ]);
        if ($userId <= 0) {
            return ErrorResponse::create(500, 'auth.google_provision_failed', 'Could not provision your account.');
        }

        $tokens        = new TokenService(DEFYN_JWT_SECRET);
        $access        = $tokens->issueAccess($userId);
        $refresh       = $tokens->issueRefresh($userId);
        $refreshClaims = $tokens->decode($refresh);
        (new RefreshTokenStore())->remember($userId, (string) $refreshClaims['jti'], (int) $refreshClaims['exp']);

        // Mirror AuthLoginController exactly: IP from REMOTE_ADDR only — never
        // X-Forwarded-For (spoofable). The plan draft used get_header('X-Forwarded-For')
        // but this codebase trusts only REMOTE_ADDR (see AuthLoginController line 39).
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        (new ActivityLogger())->log($userId, null, 'auth.login', ['method' => 'google'], $ip);

        $response = new WP_REST_Response(['access_token' => $access], 200);
        $response->header('Set-Cookie', AuthLoginController::buildRefreshCookie($refresh, (int) $refreshClaims['exp']));

        return $response;
    }
}
