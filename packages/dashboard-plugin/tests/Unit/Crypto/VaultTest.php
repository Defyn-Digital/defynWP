<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Crypto;

use Defyn\Dashboard\Crypto\Vault;
use PHPUnit\Framework\TestCase;

final class VaultTest extends TestCase
{
    public function testGenerateKeyReturnsBase64Of32Bytes(): void
    {
        $key = Vault::generateKey();

        $raw = base64_decode($key, true);
        self::assertNotFalse($raw, 'generated key must be valid base64');
        self::assertSame(32, strlen($raw), 'sodium secretbox keys are 32 bytes');
    }

    public function testEncryptDecryptRoundTripReturnsOriginalPlaintext(): void
    {
        $vault = new Vault(Vault::generateKey());
        $plaintext = 'super secret private key bytes here';

        $envelope = $vault->encrypt($plaintext);
        $recovered = $vault->decrypt($envelope);

        self::assertSame($plaintext, $recovered);
    }

    public function testEncryptingSamePlaintextTwiceProducesDifferentCiphertexts(): void
    {
        // Random nonce per call ensures ciphertext indistinguishability.
        $vault = new Vault(Vault::generateKey());
        $a = $vault->encrypt('hello');
        $b = $vault->encrypt('hello');

        self::assertNotSame($a, $b, 'random nonce should make repeated encryptions distinct');
    }

    public function testEnvelopeIsBase64(): void
    {
        $vault = new Vault(Vault::generateKey());
        $envelope = $vault->encrypt('x');

        self::assertNotFalse(base64_decode($envelope, true), 'envelope must be valid base64');
    }
}
