<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Crypto;

use InvalidArgumentException;

/**
 * Sign and verify HTTP requests using Ed25519 + a canonical string format.
 *
 * Canonical string per spec § 5.2:
 *   METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + sha256(BODY)
 *
 * Both sides MUST produce identical canonical strings — that's why canonical()
 * is public (so both signer and verifier call the exact same code, and tests
 * can pin the format byte-for-byte).
 */
final class Signer
{
    /** @var string raw 64-byte Ed25519 secret key (libsodium format) */
    private $privateKeyRaw;

    public function __construct(string $privateKeyBase64)
    {
        $raw = base64_decode($privateKeyBase64, true);
        if ($raw === false || strlen($raw) !== 64) {
            throw new InvalidArgumentException('Signer requires a base64-encoded 64-byte Ed25519 secret key.');
        }
        $this->privateKeyRaw = $raw;
    }

    /**
     * @return array{X-Defyn-Timestamp: string, X-Defyn-Nonce: string, X-Defyn-Signature: string}
     */
    public function signRequest(string $method, string $path, string $body = ''): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));  // 32-char hex nonce; 128 bits of entropy is plenty for uniqueness
        $canonical = self::canonical($method, $path, $timestamp, $nonce, $body);

        $signature = sodium_crypto_sign_detached($canonical, $this->privateKeyRaw);

        return [
            'X-Defyn-Timestamp' => $timestamp,
            'X-Defyn-Nonce'     => $nonce,
            'X-Defyn-Signature' => base64_encode($signature),
        ];
    }

    /**
     * Verify a signed request. Returns one of the VerificationResult constants.
     *
     * F2 implements only the happy path. Task 7 extends with
     * MISSING_HEADERS, EXPIRED_TIMESTAMP, INVALID_SIGNATURE, REPLAYED_NONCE.
     *
     * @param array<string, string> $headers must contain X-Defyn-Timestamp,
     *                                       X-Defyn-Nonce, X-Defyn-Signature
     */
    public static function verifyRequest(
        string $publicKeyBase64,
        string $method,
        string $path,
        string $body,
        array $headers,
        NonceStore $nonceStore,
        int $maxAgeSeconds = 300,
        ?int $now = null
    ): string {
        $publicKey = base64_decode($publicKeyBase64, true);
        $signature = base64_decode($headers['X-Defyn-Signature'], true);
        $canonical = self::canonical(
            $method,
            $path,
            $headers['X-Defyn-Timestamp'],
            $headers['X-Defyn-Nonce'],
            $body
        );

        if (!sodium_crypto_sign_verify_detached($signature, $canonical, $publicKey)) {
            return VerificationResult::INVALID_SIGNATURE;
        }

        return VerificationResult::VALID;
    }

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
