<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Crypto;

use InvalidArgumentException;
use RuntimeException;

/**
 * Authenticated symmetric encryption for at-rest secrets (e.g. per-site
 * dashboard private keys stored in wp_defyn_sites.our_private_key).
 *
 * Uses sodium_crypto_secretbox (XSalsa20 + Poly1305 MAC), not raw AES-256.
 * Spec § 4.3 says "AES-256"; we use sodium because:
 *   - Equivalent security (256-bit confidentiality + 128-bit MAC)
 *   - Authenticated by default (catches tampering automatically)
 *   - Already a libsodium project (Signer uses Ed25519 from same library)
 *   - Smaller misuse surface than OpenSSL's AES-256-GCM
 *
 * Envelope format:  base64( nonce || ciphertext )
 *   - nonce: 24 random bytes per encrypt
 *   - ciphertext: includes the 16-byte Poly1305 MAC at the start (sodium handles)
 */
final class Vault
{
    private const NONCE_BYTES = 24;  // SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
    private const KEY_BYTES   = 32;  // SODIUM_CRYPTO_SECRETBOX_KEYBYTES

    /** @var string raw 32-byte key */
    private $key;

    public function __construct(string $keyBase64)
    {
        $raw = base64_decode($keyBase64, true);
        if ($raw === false || strlen($raw) !== self::KEY_BYTES) {
            throw new InvalidArgumentException(
                'Vault requires a base64-encoded ' . self::KEY_BYTES . '-byte key.'
            );
        }
        $this->key = $raw;
    }

    /** Returns a base64-encoded fresh 32-byte key suitable for the constructor. */
    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $envelopeBase64): string
    {
        $bytes = base64_decode($envelopeBase64, true);
        if ($bytes === false) {
            throw new RuntimeException('Vault envelope is not valid base64.');
        }
        if (strlen($bytes) < self::NONCE_BYTES + 1) {
            throw new RuntimeException('Vault envelope is too short to contain a nonce + ciphertext.');
        }

        $nonce = substr($bytes, 0, self::NONCE_BYTES);
        $ciphertext = substr($bytes, self::NONCE_BYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plaintext === false) {
            // Either tampered ciphertext, or wrong key. We can't tell which from sodium's API.
            throw new RuntimeException('Vault decryption failed (tampered ciphertext or wrong key).');
        }

        return $plaintext;
    }
}
