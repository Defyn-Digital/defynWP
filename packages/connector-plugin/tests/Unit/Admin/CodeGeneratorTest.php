<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\Admin;

use Defyn\Connector\Admin\CodeGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
final class CodeGeneratorTest extends TestCase
{
    public function testGenerateProducesCodeOfLength12FromAllowedAlphabet(): void
    {
        $result = CodeGenerator::generate(now: 1_700_000_000);

        self::assertSame(12, strlen($result['code']));
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{12}$/', $result['code']);
    }

    public function testGenerateProducesUniqueCodes(): void
    {
        $a = CodeGenerator::generate(now: 1_700_000_000);
        $b = CodeGenerator::generate(now: 1_700_000_000);

        self::assertNotSame($a['code'], $b['code']);
    }

    public function testGenerateProduces32ByteNonceAsBase64(): void
    {
        $result = CodeGenerator::generate(now: 1_700_000_000);

        $nonceRaw = base64_decode($result['nonce'], true);
        self::assertNotFalse($nonceRaw);
        self::assertSame(32, strlen($nonceRaw));
    }

    public function testGenerateSetsExpires15MinutesAhead(): void
    {
        $now = 1_700_000_000;
        $result = CodeGenerator::generate(now: $now);

        self::assertSame($now + (15 * 60), $result['expires_at']);
    }

    public function testGenerateSetsCreatedAtToProvidedNow(): void
    {
        $now = 1_700_000_000;
        $result = CodeGenerator::generate(now: $now);

        self::assertSame($now, $result['created_at']);
    }
}
