<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\Crypto;

use Defyn\Connector\Crypto\TransientNonceStore;
use WP_UnitTestCase;

/**
 * @group unit
 *
 * Uses WP_UnitTestCase because TransientNonceStore reads/writes WP transients;
 * we want the real transient API behavior, not a hand-rolled fake.
 */
final class NonceStoreTest extends WP_UnitTestCase
{
    private TransientNonceStore $store;

    public function setUp(): void
    {
        parent::setUp();
        $this->store = new TransientNonceStore();
    }

    public function testFirstRememberReturnsTrue(): void
    {
        // Arrange
        $nonce = 'nonce-' . bin2hex(random_bytes(8));

        // Act
        $result = $this->store->remember($nonce, 60);

        // Assert
        self::assertTrue($result);
    }

    public function testReplayOfSameNonceReturnsFalse(): void
    {
        // Arrange
        $nonce = 'nonce-' . bin2hex(random_bytes(8));

        // Act
        $first = $this->store->remember($nonce, 60);
        $second = $this->store->remember($nonce, 60);

        // Assert
        self::assertTrue($first);
        self::assertFalse($second);
    }

    public function testDifferentNoncesDoNotCollide(): void
    {
        // Arrange
        $nonceA = 'nonce-a-' . bin2hex(random_bytes(8));
        $nonceB = 'nonce-b-' . bin2hex(random_bytes(8));

        // Act
        $resultA = $this->store->remember($nonceA, 60);
        $resultB = $this->store->remember($nonceB, 60);

        // Assert
        self::assertTrue($resultA);
        self::assertTrue($resultB);
    }

    public function testRawNonceIsNotUsableAsTransientKey(): void
    {
        // Arrange — a raw payload chosen to expose any pass-through to transient keys.
        $rawNonce = 'payload-' . bin2hex(random_bytes(8));

        // Act
        $stored = $this->store->remember($rawNonce, 60);

        // Assert — storing succeeded, but the raw string must not be the transient key.
        self::assertTrue($stored);
        self::assertFalse(get_transient($rawNonce));
    }
}
