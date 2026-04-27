<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Auth;

use Defyn\Dashboard\Auth\RefreshTokenStore;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class RefreshTokenStoreTest extends WP_UnitTestCase
{
    public function testRememberAddsJtiForUser(): void
    {
        $userId = self::factory()->user->create();
        $store = new RefreshTokenStore();

        $store->remember($userId, 'jti-1', time() + 3600);

        self::assertTrue($store->isActive($userId, 'jti-1'));
    }

    public function testIsActiveReturnsFalseForUnknownJti(): void
    {
        $userId = self::factory()->user->create();
        $store = new RefreshTokenStore();

        self::assertFalse($store->isActive($userId, 'unknown-jti'));
    }

    public function testRevokeRemovesJti(): void
    {
        $userId = self::factory()->user->create();
        $store = new RefreshTokenStore();

        $store->remember($userId, 'jti-1', time() + 3600);
        $store->revoke($userId, 'jti-1');

        self::assertFalse($store->isActive($userId, 'jti-1'));
    }

    public function testIsActiveReturnsFalseForExpiredJti(): void
    {
        $userId = self::factory()->user->create();
        $store = new RefreshTokenStore();

        $store->remember($userId, 'jti-1', time() - 1);  // already expired

        self::assertFalse($store->isActive($userId, 'jti-1'));
    }

    public function testJtisAreScopedPerUser(): void
    {
        $u1 = self::factory()->user->create();
        $u2 = self::factory()->user->create();
        $store = new RefreshTokenStore();

        $store->remember($u1, 'jti-shared', time() + 3600);

        self::assertTrue($store->isActive($u1, 'jti-shared'));
        self::assertFalse($store->isActive($u2, 'jti-shared'), 'JTIs are scoped per user');
    }

    public function testRememberMultipleJtisForSameUser(): void
    {
        $userId = self::factory()->user->create();
        $store = new RefreshTokenStore();

        $store->remember($userId, 'jti-1', time() + 3600);
        $store->remember($userId, 'jti-2', time() + 3600);
        $store->remember($userId, 'jti-3', time() + 3600);

        self::assertTrue($store->isActive($userId, 'jti-1'));
        self::assertTrue($store->isActive($userId, 'jti-2'));
        self::assertTrue($store->isActive($userId, 'jti-3'));
    }
}
