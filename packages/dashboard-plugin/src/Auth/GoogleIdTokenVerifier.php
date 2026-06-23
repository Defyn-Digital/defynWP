<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Auth;

use Defyn\Dashboard\Auth\Exceptions\GoogleAuthException;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Throwable;

/**
 * Verifies a Google-issued ID token (OIDC) and enforces the Workspace domain.
 * `decode()` (signature + expiry against Google's JWKS) is protected so unit
 * tests can stub it with canned claims instead of real RSA.
 */
class GoogleIdTokenVerifier
{
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const CERTS_TRANSIENT = 'defyn_google_jwks';
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public function __construct(private readonly string $clientId) {}

    /** @return array<string,mixed> verified claims @throws GoogleAuthException */
    public function verify(string $idToken): array
    {
        try {
            $claims = $this->decode($idToken);
        } catch (Throwable $e) {
            throw new GoogleAuthException('auth.google_invalid', 401, 'Google token is invalid or expired.');
        }

        if (!in_array((string) ($claims['iss'] ?? ''), self::ISSUERS, true)) {
            throw new GoogleAuthException('auth.google_invalid', 401, 'Unexpected token issuer.');
        }
        if ((string) ($claims['aud'] ?? '') !== $this->clientId) {
            throw new GoogleAuthException('auth.google_invalid', 401, 'Token audience mismatch.');
        }
        $verified = $claims['email_verified'] ?? false;
        if ($verified !== true && $verified !== 'true') {
            throw new GoogleAuthException('auth.google_invalid', 401, 'Email is not verified.');
        }

        $email = (string) ($claims['email'] ?? '');
        $hd = (string) ($claims['hd'] ?? '');
        if (!DomainPolicy::isAllowedHd($hd) || !DomainPolicy::isAllowedEmail($email)) {
            throw new GoogleAuthException('auth.google_domain', 403, 'Only ' . DomainPolicy::ALLOWED . ' accounts may sign in.');
        }

        return $claims;
    }

    /**
     * Decode + verify the JWT signature against Google's rotating JWKS.
     * firebase/php-jwt throws on bad signature/expiry. Overridable in tests.
     * @return array<string,mixed>
     */
    protected function decode(string $idToken): array
    {
        $keys = JWK::parseKeySet($this->googleCerts());
        return (array) JWT::decode($idToken, $keys);
    }

    /** @return array<string,mixed> the raw JWKS document, cached in a transient. */
    protected function googleCerts(): array
    {
        $cached = get_transient(self::CERTS_TRANSIENT);
        if (is_array($cached) && isset($cached['keys'])) {
            return $cached;
        }
        $res = wp_remote_get(self::CERTS_URL, ['timeout' => 10]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            throw new GoogleAuthException('auth.google_invalid', 401, 'Could not fetch Google certificates.');
        }
        $json = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($json) || !isset($json['keys'])) {
            throw new GoogleAuthException('auth.google_invalid', 401, 'Malformed Google certificates.');
        }
        set_transient(self::CERTS_TRANSIENT, $json, HOUR_IN_SECONDS);
        return $json;
    }
}
