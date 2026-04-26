<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Crypto;

/**
 * Sign and verify HTTP requests using Ed25519 + a canonical string format.
 *
 * Canonical string per spec § 5.2:
 *   METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + sha256(BODY)
 *
 * Both sides MUST produce identical canonical strings — that's why this method
 * is public (so both signer and verifier call the exact same code, and tests
 * can pin the format byte-for-byte).
 */
final class Signer
{
    public static function canonical(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body
    ): string {
        return strtoupper($method) . "\n"
            . $path . "\n"
            . $timestamp . "\n"
            . $nonce . "\n"
            . hash('sha256', $body);
    }
}
