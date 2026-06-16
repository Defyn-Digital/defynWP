<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\SitePerformance;
use PHPUnit\Framework\TestCase;

final class SitePerformanceTest extends TestCase
{
    public function testFromRowAndToJson(): void
    {
        $p = SitePerformance::fromRow([
            'id' => '5', 'site_id' => '2',
            'mobile_score' => '82', 'mobile_lcp_ms' => '2100', 'mobile_cls' => '0.140', 'mobile_inp_ms' => '180',
            'desktop_score' => '96', 'desktop_lcp_ms' => '900', 'desktop_cls' => '0.010', 'desktop_inp_ms' => '60',
            'fetched_at' => '2026-06-14 03:00:00', 'created_at' => '2026-06-14 03:00:05',
        ]);
        self::assertSame(5, $p->id);
        self::assertSame(82, $p->mobileScore);
        self::assertSame(0.14, $p->mobileCls);
        $json = $p->toJson();
        self::assertSame(82, $json['mobile_score']);
        self::assertSame(96, $json['desktop_score']);
        self::assertSame('2026-06-14 03:00:00', $json['fetched_at']);
    }

    public function testNullMetricsSurviveRoundTrip(): void
    {
        $p = SitePerformance::fromRow([
            'id' => '1', 'site_id' => '2',
            'mobile_score' => '70', 'mobile_lcp_ms' => '3000', 'mobile_cls' => '0.050', 'mobile_inp_ms' => '210',
            'desktop_score' => null, 'desktop_lcp_ms' => null, 'desktop_cls' => null, 'desktop_inp_ms' => null,
            'fetched_at' => '2026-06-14 03:00:00', 'created_at' => '2026-06-14 03:00:05',
        ]);
        self::assertNull($p->desktopScore);
        self::assertNull($p->toJson()['desktop_score']);
    }
}
