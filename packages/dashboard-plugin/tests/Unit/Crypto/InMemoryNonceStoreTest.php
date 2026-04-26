<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Crypto;

use Defyn\Dashboard\Crypto\InMemoryNonceStore;
use PHPUnit\Framework\TestCase;

final class InMemoryNonceStoreTest extends TestCase
{
    public function testFirstRememberReturnsTrueIndicatingFreshlyStored(): void
    {
        $store = new InMemoryNonceStore();
        self::assertTrue($store->remember('abc', 600), 'first remember should return true (newly stored)');
    }

    public function testSecondRememberOfSameNonceReturnsFalseIndicatingReplay(): void
    {
        $store = new InMemoryNonceStore();
        $store->remember('abc', 600);
        self::assertFalse($store->remember('abc', 600), 'second remember of same nonce should return false (replay)');
    }

    public function testDifferentNoncesAreIndependent(): void
    {
        $store = new InMemoryNonceStore();
        self::assertTrue($store->remember('abc', 600));
        self::assertTrue($store->remember('xyz', 600), 'different nonce should also return true');
    }
}
