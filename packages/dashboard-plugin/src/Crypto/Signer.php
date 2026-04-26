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
     * Order of checks (each cheap rejects before expensive ones):
     *   1. All three headers present
     *   2. Timestamp within ±$maxAgeSeconds of $now
     *   3. Public key + signature decode + length sanity
     *   4. Signature valid against canonical string
     *   5. Nonce not previously seen (and store it now if not)
     *
     * @param array<string, string> $headers must contain X-Defyn-Timestamp,
     *                                       X-Defyn-Nonce, X-Defyn-Signature
     * @param int|null $now overrideable for tests; defaults to time()
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
        if (!isset($headers['X-Defyn-Timestamp'], $headers['X-Defyn-Nonce'], $headers['X-Defyn-Signature'])) {
            return VerificationResult::MISSING_HEADERS;
        }

        $timestamp = $headers['X-Defyn-Timestamp'];
        $nonce     = $headers['X-Defyn-Nonce'];
        $sigB64    = $headers['X-Defyn-Signature'];

        $now = $now ?? time();
        $age = abs($now - (int) $timestamp);
        if ($age > $maxAgeSeconds) {
            return VerificationResult::EXPIRED_TIMESTAMP;
        }

        $publicKey = base64_decode($publicKeyBase64, true);
        $signature = base64_decode($sigB64, true);
        if ($publicKey === false || $signature === false) {
            return VerificationResult::INVALID_SIGNATURE;
        }
        // Length sanity — Ed25519 has fixed sizes. Without these checks libsodium throws.
        if (strlen($publicKey) !== 32 || strlen($signature) !== 64) {
            return VerificationResult::INVALID_SIGNATURE;
        }

        $canonical = self::canonical($method, $path, $timestamp, $nonce, $body);
        if (!sodium_crypto_sign_verify_detached($signature, $canonical, $publicKey)) {
            return VerificationResult::INVALID_SIGNATURE;
        }

        // Signature is valid. Now check (and atomically store) the nonce so a
        // genuine signed request can't be replayed by an attacker who captured it.
        // TTL = 2 × maxAgeSeconds gives buffer for clock skew while keeping the
        // store bounded in size.
        if (!$nonceStore->remember($nonce, $maxAgeSeconds * 2)) {
            return VerificationResult::REPLAYED_NONCE;
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
