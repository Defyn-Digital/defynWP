<?php

declare(strict_types=1);

namespace Defyn\Connector\Admin;

/**
 * Generates a connection code (human-readable) + nonce (random) + expiry.
 *
 * Alphabet excludes I, O, 0, 1 to avoid visual ambiguity when the user
 * reads the code off a screen and types it into the SPA.
 */
final class CodeGenerator
{
    public const TTL_SECONDS = 15 * 60;
    public const ALPHABET    = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const CODE_LENGTH = 12;
    public const NONCE_BYTES = 32;

    /**
     * @param int|null $now Override clock for tests; defaults to time().
     * @return array{code: string, nonce: string, created_at: int, expires_at: int}
     */
    public static function generate(?int $now = null): array
    {
        $now ??= time();

        $code   = '';
        $alphaLen = strlen(self::ALPHABET);
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $alphaLen - 1)];
        }

        return [
            'code'       => $code,
            'nonce'      => base64_encode(random_bytes(self::NONCE_BYTES)),
            'created_at' => $now,
            'expires_at' => $now + self::TTL_SECONDS,
        ];
    }
}
