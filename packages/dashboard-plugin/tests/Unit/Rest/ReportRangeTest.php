<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Rest;

use Defyn\Dashboard\Rest\Support\ReportRange;
use Defyn\Dashboard\Rest\Support\InvalidReportRange;
use PHPUnit\Framework\TestCase;

final class ReportRangeTest extends TestCase
{
    public function testExplicitRangeNormalizesToFullDayBounds(): void
    {
        $r = ReportRange::resolve('2026-05-16', '2026-06-15');
        self::assertSame('2026-05-16 00:00:00', $r['from']);
        self::assertSame('2026-06-15 23:59:59', $r['to']);
        self::assertSame('2026-05-16', $r['from_date']);
        self::assertSame('2026-06-15', $r['to_date']);
    }

    public function testBothAbsentDefaultsToTrailing30d(): void
    {
        $r = ReportRange::resolve(null, null);
        self::assertNotEmpty($r['from']); self::assertNotEmpty($r['to']);
    }

    public function testFromAfterToThrowsInvalid(): void
    {
        try { ReportRange::resolve('2026-06-15', '2026-05-01'); self::fail('expected throw'); }
        catch (InvalidReportRange $e) { self::assertSame('report.invalid_range', $e->code); }
    }

    public function testMalformedThrowsInvalid(): void
    {
        $this->expectException(InvalidReportRange::class);
        ReportRange::resolve('not-a-date', '2026-06-15');
    }

    public function testTooLargeThrowsRangeTooLarge(): void
    {
        try { ReportRange::resolve('2020-01-01', '2026-01-01'); self::fail('expected throw'); }
        catch (InvalidReportRange $e) { self::assertSame('report.range_too_large', $e->code); }
    }
}
