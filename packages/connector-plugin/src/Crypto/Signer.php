<?php

declare(strict_types=1);

namespace Defyn\Connector\Crypto;

use InvalidArgumentException;

/**
 * Signs an arbitrary string with the connector's Ed25519 private key.
 *
 * F5: signs the dashboard's `callback_challenge` directly. No canonical
 * format wrapping (the dashboard verifies against the raw challenge).
 * F6 will add a separate request-signing path with the spec § 5.2
 * canonical string for outbound /status/heartbeat calls.
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
}
