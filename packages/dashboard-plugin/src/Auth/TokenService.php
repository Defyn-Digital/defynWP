<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Auth;

use Defyn\Dashboard\Auth\Exceptions\InvalidTokenException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use InvalidArgumentException;
use Throwable;

/**
 * Issues and decodes JWT access + refresh tokens (HS256).
 *
 * Pure unit — no WP, no DB. RefreshTokenStore handles per-user JTI persistence.
 *
 * Token shapes:
 *   access:  { sub: int (user_id), typ: 'access',  iat: int, exp: int }   TTL 15 min
 *   refresh: { sub: int (user_id), typ: 'refresh', iat: int, exp: int, jti: string }   TTL 30 days
 *
 * Note on typ: this class does NOT enforce that the caller asks for the right
 * token type. Callers (RequireAuth middleware, AuthRefreshController) must
 * inspect `claims['typ']` themselves and reject mismatches.
 */
final class TokenService
{
    public const ACCESS_TTL_SECONDS  = 15 * 60;
    public const REFRESH_TTL_SECONDS = 30 * 24 * 60 * 60;
    public const TYPE_ACCESS         = 'access';
    public const TYPE_REFRESH        = 'refresh';
    public const MIN_SECRET_BYTES    = 32;

    private const ALG = 'HS256';

    public function __construct(private readonly string $secret)
    {
        if (strlen($secret) < self::MIN_SECRET_BYTES) {
            throw new InvalidArgumentException(
                'JWT secret must be at least ' . self::MIN_SECRET_BYTES . ' bytes.'
            );
        }
    }

    public function issueAccess(int $userId, ?int $now = null): string
    {
        $now = $now ?? time();
        return JWT::encode([
            'sub' => $userId,
            'typ' => self::TYPE_ACCESS,
            'iat' => $now,
            'exp' => $now + self::ACCESS_TTL_SECONDS,
        ], $this->secret, self::ALG);
    }

    public function issueRefresh(int $userId, ?int $now = null): string
    {
        $now = $now ?? time();
        return JWT::encode([
            'sub' => $userId,
            'typ' => self::TYPE_REFRESH,
            'iat' => $now,
            'exp' => $now + self::REFRESH_TTL_SECONDS,
            'jti' => self::generateJti(),
        ], $this->secret, self::ALG);
    }

    /**
     * Decode a token. Returns claims as an associative array.
     *
     * Caller is responsible for checking `claims['typ']` matches what they
     * expect (access vs. refresh). This method only validates signature,
     * structure, and expiry.
     *
     * @throws InvalidTokenException on malformed, bad-signature, or expired token.
     *
     * @internal `JWT::$timestamp` is a process-global. While this method holds
     *           the injected $now value, any other code path in the same process
     *           that calls `JWT::decode()` directly will see the same injected
     *           value. Safe under PHP-FPM (single-threaded per request); not safe
     *           under concurrent worker models like Swoole or RoadRunner.
     */
    public function decode(string $token, ?int $now = null): array
    {
        if ($now !== null) {
            JWT::$timestamp = $now;
        }
        try {
            $decoded = (array) JWT::decode($token, new Key($this->secret, self::ALG));
        } catch (Throwable $e) {
            throw new InvalidTokenException($e->getMessage(), 0, $e);
        } finally {
            JWT::$timestamp = null;  // restore real-clock decoding for other callers
        }

        return $decoded;
    }

    private static function generateJti(): string
    {
        // 16 random bytes hex = 32-char unique-enough ID. Not cryptographically
        // sensitive (signature provides authenticity) — just unique.
        return bin2hex(random_bytes(16));
    }
}
