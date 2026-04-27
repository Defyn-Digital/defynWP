<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Auth;

use Defyn\Dashboard\Auth\Exceptions\InvalidTokenException;
use Defyn\Dashboard\Auth\TokenService;
use PHPUnit\Framework\TestCase;

final class TokenServiceTest extends TestCase
{
    private const SECRET = 'unit-test-secret-32-chars-minimum';

    public function testIssueAccessProducesDecodableToken(): void
    {
        $svc = new TokenService(self::SECRET);
        $token = $svc->issueAccess(42);

        $claims = $svc->decode($token);

        self::assertSame(42, $claims['sub']);
        self::assertSame('access', $claims['typ']);
        self::assertArrayHasKey('iat', $claims, 'access tokens must carry an iat claim');
        self::assertIsInt($claims['iat']);
    }

    public function testIssueRefreshIncludesUniqueJti(): void
    {
        $svc = new TokenService(self::SECRET);
        $a = $svc->decode($svc->issueRefresh(42));
        $b = $svc->decode($svc->issueRefresh(42));

        self::assertNotSame($a['jti'], $b['jti'], 'each refresh must have a unique JTI');
        self::assertSame('refresh', $a['typ']);
    }

    public function testAccessTokenExpiresIn15Minutes(): void
    {
        $svc = new TokenService(self::SECRET);
        $now = 1_700_000_000;
        $token = $svc->issueAccess(42, $now);
        $claims = $svc->decode($token, $now);

        self::assertSame($now + 15 * 60, $claims['exp']);
    }

    public function testRefreshTokenExpiresIn30Days(): void
    {
        $svc = new TokenService(self::SECRET);
        $now = 1_700_000_000;
        $token = $svc->issueRefresh(42, $now);
        $claims = $svc->decode($token, $now);

        self::assertSame($now + 30 * 24 * 60 * 60, $claims['exp']);
    }

    public function testDecodeRejectsTokenSignedWithDifferentSecret(): void
    {
        $svcA = new TokenService('secret-a-32-bytes-minimum-padding');
        $svcB = new TokenService('secret-b-32-bytes-minimum-padding');
        $token = $svcA->issueAccess(42);

        $this->expectException(InvalidTokenException::class);
        $svcB->decode($token);
    }

    public function testDecodeRejectsExpiredToken(): void
    {
        $svc = new TokenService(self::SECRET);
        $past = 1_700_000_000;
        $token = $svc->issueAccess(42, $past);

        // Decode 1 hour later; access TTL is 15 min so this is well past.
        $this->expectException(InvalidTokenException::class);
        $svc->decode($token, $past + 3600);
    }

    public function testDecodeRejectsMalformedToken(): void
    {
        $svc = new TokenService(self::SECRET);

        $this->expectException(InvalidTokenException::class);
        $svc->decode('not.a.jwt');
    }

    public function testConstructorRejectsShortSecret(): void
    {
        // HS256 requires at least 32 bytes of secret entropy.
        $this->expectException(\InvalidArgumentException::class);
        new TokenService('too-short');
    }

    public function testConstructorAcceptsExactly32ByteSecret(): void
    {
        // Boundary: 32 bytes exactly should be accepted (constructor uses < not <=).
        $svc = new TokenService(str_repeat('a', TokenService::MIN_SECRET_BYTES));

        // No assertion on the constructor itself; success is "no exception thrown".
        // Sanity: the resulting service can issue and decode.
        $token = $svc->issueAccess(1);
        self::assertNotEmpty($token);
    }
}
