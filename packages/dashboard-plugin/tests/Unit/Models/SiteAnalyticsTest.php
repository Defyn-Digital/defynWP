<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\SiteAnalytics;
use PHPUnit\Framework\TestCase;

final class SiteAnalyticsTest extends TestCase
{
    public function testFromRowDecodesJsonAndToJsonRoundTrips(): void
    {
        $row = [
            'id' => '5', 'site_id' => '3',
            'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
            'sessions' => '12480', 'total_users' => '9210', 'screen_page_views' => '31540',
            'avg_session_duration' => '108.50',
            'top_pages' => json_encode([['path'=>'/','title'=>'Home','views'=>8420]]),
            'channels'  => json_encode([['channel'=>'Organic Search','sessions'=>5200]]),
            'fetched_at' => '2026-06-30 03:00:00', 'created_at' => '2026-06-30 03:00:05',
        ];
        $a = SiteAnalytics::fromRow($row);
        self::assertSame(12480, $a->sessions);
        self::assertSame(108.5, $a->avgSessionDuration);
        self::assertSame('Home', $a->topPages[0]['title']);
        self::assertSame('Organic Search', $a->channels[0]['channel']);

        $json = $a->toJson();
        self::assertSame('2026-06-01', $json['period_start']);
        self::assertSame(9210, $json['total_users']);
        self::assertSame([['path'=>'/','title'=>'Home','views'=>8420]], $json['top_pages']);
    }

    public function testFromRowToleratesNullJsonAndMetrics(): void
    {
        $row = [
            'id' => '1', 'site_id' => '1', 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
            'sessions' => null, 'total_users' => null, 'screen_page_views' => null, 'avg_session_duration' => null,
            'top_pages' => null, 'channels' => null,
            'fetched_at' => '2026-06-01 03:00:00', 'created_at' => '2026-06-01 03:00:00',
        ];
        $a = SiteAnalytics::fromRow($row);
        self::assertNull($a->sessions);
        self::assertSame([], $a->topPages);
        self::assertSame([], $a->channels);
    }
}
