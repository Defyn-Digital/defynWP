<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Crypto;

/**
 * Ed25519 keypair, base64-encoded for safe storage and transport.
 *
 * Use ::generate() to create a fresh pair. Both halves are exposed as
 * public properties — they're immutable by convention (the constructor
 * sets them once, and there's no setter).
 *
 * Sizes (after base64 decoding):
 *   - publicKey:  32 bytes
 *   - privateKey: 64 bytes (libsodium's "secret key" format = seed + public)
 */
final class KeyPair
{
    /** @var string base64-encoded 32-byte Ed25519 public key */
    public $publicKey;

    /** @var string base64-encoded 64-byte Ed25519 secret key (libsodium format) */
    public $privateKey;

    public function __construct(string $publicKey, string $privateKey)
    {
        $this->publicKey = $publicKey;
        $this->privateKey = $privateKey;
    }

    public static function generate(): self
    {
        $pair = sodium_crypto_sign_keypair();

        return new self(
            base64_encode(sodium_crypto_sign_publickey($pair)),
            base64_encode(sodium_crypto_sign_secretkey($pair))
        );
    }
}
