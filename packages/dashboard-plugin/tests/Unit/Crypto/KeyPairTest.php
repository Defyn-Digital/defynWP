<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Crypto;

use Defyn\Dashboard\Crypto\KeyPair;
use PHPUnit\Framework\TestCase;

final class KeyPairTest extends TestCase
{
    public function testGenerateProducesPublicAndPrivateKeys(): void
    {
        $pair = KeyPair::generate();

        self::assertNotEmpty($pair->publicKey);
        self::assertNotEmpty($pair->privateKey);
    }

    public function testKeysAreBase64Encoded(): void
    {
        $pair = KeyPair::generate();

        // base64_decode with strict=true returns false on invalid input.
        self::assertNotFalse(base64_decode($pair->publicKey, true), 'publicKey must be valid base64');
        self::assertNotFalse(base64_decode($pair->privateKey, true), 'privateKey must be valid base64');
    }

    public function testEd25519KeyLengthsAreCorrect(): void
    {
        $pair = KeyPair::generate();

        // Ed25519: 32-byte public key, 64-byte secret key (libsodium concatenates seed + public).
        self::assertSame(32, strlen(base64_decode($pair->publicKey, true)), 'Ed25519 public keys are 32 bytes');
        self::assertSame(64, strlen(base64_decode($pair->privateKey, true)), 'Ed25519 secret keys are 64 bytes (libsodium format)');
    }

    public function testEachCallProducesDifferentKeys(): void
    {
        $a = KeyPair::generate();
        $b = KeyPair::generate();

        self::assertNotSame($a->publicKey, $b->publicKey);
        self::assertNotSame($a->privateKey, $b->privateKey);
    }
}
