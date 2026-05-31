<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Services;

use Defyn\Dashboard\Services\UrlValidator;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 *
 * F10 Task 2 — verifies the validator accepts IPv6-only hosts by checking
 * both A and AAAA records via an injectable resolver. The default resolver
 * uses dns_get_record() with the DNS_A | DNS_AAAA bitmask; these tests fake
 * the resolver so they stay deterministic and offline.
 */
final class UrlValidatorIPv6Test extends TestCase
{
    public function testRejectsHostWithNoRecords(): void
    {
        $resolver = static fn(string $host): array => [];
        $v = new UrlValidator(checkDns: true, dnsResolver: $resolver);
        $result = $v->validate('https://no-records.test');
        self::assertFalse($result->isValid);
        self::assertSame('sites.invalid_url', $result->errorCode);
    }

    public function testAcceptsIpv6OnlyHostWhenAAAARecordResolves(): void
    {
        $resolver = static fn(string $host): array => [
            ['type' => 'AAAA', 'ipv6' => '2001:db8::1'],
        ];
        $v = new UrlValidator(checkDns: true, dnsResolver: $resolver);
        $result = $v->validate('https://ipv6-only.test');
        self::assertTrue($result->isValid);
    }

    public function testAcceptsIpv4OnlyHostWhenARecordResolves(): void
    {
        $resolver = static fn(string $host): array => [
            ['type' => 'A', 'ip' => '203.0.113.42'],
        ];
        $v = new UrlValidator(checkDns: true, dnsResolver: $resolver);
        $result = $v->validate('https://ipv4-only.test');
        self::assertTrue($result->isValid);
    }

    public function testAcceptsHostWithBothRecordTypes(): void
    {
        $resolver = static fn(string $host): array => [
            ['type' => 'A', 'ip' => '203.0.113.42'],
            ['type' => 'AAAA', 'ipv6' => '2001:db8::1'],
        ];
        $v = new UrlValidator(checkDns: true, dnsResolver: $resolver);
        $result = $v->validate('https://dual-stack.test');
        self::assertTrue($result->isValid);
    }
}
