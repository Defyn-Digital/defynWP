<?php

declare(strict_types=1);

namespace Defyn\Connector\Crypto;

use InvalidArgumentException;

/**
 * Signs arbitrary strings and verifies signed inbound requests using Ed25519.
 *
 * F5: `sign()` signs the dashboard's `callback_challenge` directly. No canonical
 * format wrapping (the dashboard verifies against the raw challenge).
 * F6 added canonical-string verification (`canonical()` + `verifyRequest()`) for
 * inbound signed requests per spec § 5.2. The canonical format MUST stay
 * byte-for-byte identical to Defyn\Dashboard\Crypto\Signer::canonical() —
 * any drift breaks signed requests in both directions.
 *
 * All methods are static — the connector deliberately holds no instance state
 * for crypto helpers (the dashboard's Signer is instance-based because it owns
 * a private key; the connector verifies with a passed-in public key per call).
 */
final class Signer
{
    public static function sign(string $message, string $privateKeyBase64): string
    {
        $raw = base64_decode($privateKeyBase64, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException(
                'Signer requires a base64-encoded ' . SODIUM_CRYPTO_SIGN_SECRETKEYBYTES . '-byte Ed25519 secret key.'
            );
        }

        return base64_encode(sodium_crypto_sign_detached($message, $raw));
    }

    /**
     * Canonical string per spec § 5.2:
     *   METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + sha256(BODY)
     *
     * Public so both signer (dashboard) and verifier (here) call identical code
     * and tests can pin the format byte-for-byte.
     */
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

    /**
     * Verify a signed inbound request. Returns one of VerificationResult constants.
     *
     * Check order (cheap rejects first — matches dashboard Signer for parity):
     *   1. All three headers present + well-formed
     *   2. Timestamp within ±$maxAgeSeconds of $now
     *   3. Key + signature decode + length sanity
     *   4. Signature valid against canonical(method, path, timestamp, nonce, body)
     *   5. Nonce not previously seen (atomically remembered here)
     *
     * @param array<string, string> $headers must contain X-Defyn-Timestamp, X-Defyn-Nonce, X-Defyn-Signature
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

        // Malformed/empty headers reported as MISSING rather than coerced — caller
        // didn't mean to send "abc" as a timestamp, and a 0 fallback would hand the
        // operator a misleading EXPIRED_TIMESTAMP.
        if (!ctype_digit($timestamp) || $nonce === '' || $sigB64 === '') {
            return VerificationResult::MISSING_HEADERS;
        }

        $now = $now ?? time();
        if (abs($now - (int) $timestamp) > $maxAgeSeconds) {
            return VerificationResult::EXPIRED_TIMESTAMP;
        }

        $publicKey = base64_decode($publicKeyBase64, true);
        $signature = base64_decode($sigB64, true);
        if ($publicKey === false || $signature === false
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return VerificationResult::INVALID_SIGNATURE;
        }

        $canonical = self::canonical($method, $path, $timestamp, $nonce, $body);
        if (!sodium_crypto_sign_verify_detached($signature, $canonical, $publicKey)) {
            return VerificationResult::INVALID_SIGNATURE;
        }

        // Signature valid — atomically check + store nonce so a captured genuine
        // request can't be replayed. TTL = 2× maxAgeSeconds for clock-skew buffer
        // while keeping the store bounded.
        if (!$nonceStore->remember($nonce, $maxAgeSeconds * 2)) {
            return VerificationResult::REPLAYED_NONCE;
        }

        return VerificationResult::VALID;
    }
}
