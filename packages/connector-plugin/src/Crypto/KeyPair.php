<?php

declare(strict_types=1);

namespace Defyn\Connector\Crypto;

/**
 * Ed25519 keypair generation via libsodium.
 * Returns base64-encoded keys for safe storage in wp_options JSON.
 */
final class KeyPair
{
    /**
     * @return array{public_key: string, private_key: string} base64-encoded
     */
    public static function generate(): array
    {
        $pair = sodium_crypto_sign_keypair();
        return [
            'public_key'  => base64_encode(sodium_crypto_sign_publickey($pair)),
            'private_key' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }
}
