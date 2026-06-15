<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\IncidentsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class IncidentsRepositoryRangeTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_incidents');
    }

    public function testFindForSiteInRangeReturnsOverlappingIncidents(): void
    {
        $repo = new IncidentsRepository();
        $id1 = $repo->open(7, '2026-05-22 02:01:00', '502 Bad Gateway');
        $repo->close($id1, '2026-05-22 02:08:00', 420);                 // closed, in range
        $id2 = $repo->open(7, '2026-01-01 00:00:00', 'old');
        $repo->close($id2, '2026-01-01 00:05:00', 300);                 // before range, excluded
        $repo->open(7, '2026-05-30 10:00:00', '503');                   // ongoing, in range
        $repo->open(8, '2026-05-22 02:01:00', 'other');                 // other site, excluded

        $rows = $repo->findForSiteInRange(7, '2026-05-16 00:00:00', '2026-06-15 23:59:59');

        self::assertCount(2, $rows);
        self::assertSame('2026-05-30 10:00:00', $rows[0]->startedAt);   // started_at DESC → ongoing first
        self::assertNull($rows[0]->endedAt);
        self::assertSame('502 Bad Gateway', $rows[1]->lastError);
        self::assertSame(420, $rows[1]->durationSeconds);
    }
}
