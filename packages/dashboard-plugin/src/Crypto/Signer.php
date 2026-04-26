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
    /** @var string base64-encoded 64-byte Ed25519 secret key */
    private $privateKeyBase64;

    public function __construct(string $privateKeyBase64)
    {
        $raw = base64_decode($privateKeyBase64, true);
        if ($raw === false || strlen($raw) !== 64) {
            throw new InvalidArgumentException('Signer requires a base64-encoded 64-byte Ed25519 secret key.');
        }
        $this->privateKeyBase64 = $privateKeyBase64;
    }

    /**
     * @return array{X-Defyn-Timestamp: string, X-Defyn-Nonce: string, X-Defyn-Signature: string}
     */
    public function signRequest(string $method, string $path, string $body): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));  // 32-char hex nonce
        $canonical = self::canonical($method, $path, $timestamp, $nonce, $body);

        $signature = sodium_crypto_sign_detached($canonical, base64_decode($this->privateKeyBase64, true));

        return [
            'X-Defyn-Timestamp' => $timestamp,
            'X-Defyn-Nonce'     => $nonce,
            'X-Defyn-Signature' => base64_encode($signature),
        ];
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
