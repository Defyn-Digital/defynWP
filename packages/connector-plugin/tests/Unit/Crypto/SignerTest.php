<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\Crypto;

use Defyn\Connector\Crypto\KeyPair;
use Defyn\Connector\Crypto\Signer;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
final class SignerTest extends TestCase
{
    public function testSignProducesBase64SignatureVerifiableWithPublicKey(): void
    {
        $pair = KeyPair::generate();
        $message = 'hello-world-' . random_bytes(8);

        $sigBase64 = Signer::sign($message, $pair['private_key']);

        $sigRaw = base64_decode($sigBase64, true);
        self::assertNotFalse($sigRaw);
        self::assertSame(SODIUM_CRYPTO_SIGN_BYTES, strlen($sigRaw));

        $pubRaw = base64_decode($pair['public_key'], true);
        self::assertTrue(sodium_crypto_sign_verify_detached($sigRaw, $message, $pubRaw));
    }

    public function testTwoSignaturesOfSameMessageAreIdentical(): void
    {
        // Ed25519 is deterministic.
        $pair = KeyPair::generate();
        $a = Signer::sign('msg', $pair['private_key']);
        $b = Signer::sign('msg', $pair['private_key']);
        self::assertSame($a, $b);
    }

    public function testInvalidBase64PrivateKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Signer::sign('msg', 'not-base64!!!');
    }

    public function testWrongLengthPrivateKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Signer::sign('msg', base64_encode(str_repeat('a', 10)));
    }
}
