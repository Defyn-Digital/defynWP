<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\Crypto;

use Defyn\Connector\Crypto\KeyPair;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
final class KeyPairTest extends TestCase
{
    public function testGenerateProducesBase64KeysOfExpectedLength(): void
    {
        $pair = KeyPair::generate();

        self::assertArrayHasKey('public_key', $pair);
        self::assertArrayHasKey('private_key', $pair);

        $publicRaw  = base64_decode($pair['public_key'], true);
        $privateRaw = base64_decode($pair['private_key'], true);

        self::assertNotFalse($publicRaw, 'public_key is not valid base64');
        self::assertNotFalse($privateRaw, 'private_key is not valid base64');

        self::assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($publicRaw));
        self::assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen($privateRaw));
    }

    public function testGenerateProducesUniqueKeysEachCall(): void
    {
        $a = KeyPair::generate();
        $b = KeyPair::generate();

        self::assertNotSame($a['public_key'], $b['public_key']);
        self::assertNotSame($a['private_key'], $b['private_key']);
    }
}
