<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Services;

use Defyn\Dashboard\Services\UrlValidator;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 *
 * DNS-checking is disabled in this test class to keep it fully offline.
 * A separate integration test (Task 9's controller test) covers the DNS path
 * against `defyn-connector.test` (a controlled localhost name).
 */
final class UrlValidatorTest extends TestCase
{
    private UrlValidator $validator;

    public function setUp(): void
    {
        $this->validator = new UrlValidator(checkDns: false);
    }

    public function testHttpsUrlPasses(): void
    {
        $result = $this->validator->validate('https://example.test');
        self::assertTrue($result->isValid);
        self::assertNull($result->errorCode);
    }

    public function testHttpUrlFailsWithInvalidUrlCode(): void
    {
        $result = $this->validator->validate('http://example.test');
        self::assertFalse($result->isValid);
        self::assertSame('sites.invalid_url', $result->errorCode);
        self::assertStringContainsString('HTTPS', $result->errorMessage);
    }

    public function testMalformedUrlFails(): void
    {
        $result = $this->validator->validate('not a url');
        self::assertFalse($result->isValid);
        self::assertSame('sites.invalid_url', $result->errorCode);
    }

    public function testEmptyUrlFails(): void
    {
        $result = $this->validator->validate('');
        self::assertFalse($result->isValid);
        self::assertSame('sites.invalid_url', $result->errorCode);
    }

    public function testUrlWithoutHostFails(): void
    {
        $result = $this->validator->validate('https://');
        self::assertFalse($result->isValid);
        self::assertSame('sites.invalid_url', $result->errorCode);
    }
}
