<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\IncidentsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P3.1 — IncidentsRepository integration tests.
 *
 * Guardrail: freshlyActivate('defyn_incidents') + explicit DELETE of both
 * incidents and sites rows in setUp so no state leaks across test runs.
 *
 * @group integration
 */
final class IncidentsRepositoryTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_incidents');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_incidents");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");
        // phpcs:enable WordPress.DB.PreparedSQL
    }

    /**
     * Insert a site row and return its id. Mirrors the seedSite helper pattern
     * from SitesRepositoryOverviewTest — direct $wpdb->insert, no SitesRepository.
     */
    private function makeSite(int $userId, string $label): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://example-' . microtime(true) . '.test',
            'label'      => $label,
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    public function test_open_then_find_open_returns_incident(): void
    {
        $siteId = $this->makeSite(1, 'AcmeBlog');
        $repo = new IncidentsRepository();
        $id = $repo->open($siteId, '2026-06-14 10:00:00', 'Connector returned status 500');
        $open = $repo->findOpenForSite($siteId);
        $this->assertNotNull($open);
        $this->assertSame($id, $open->id);
        $this->assertNull($open->endedAt);
    }

    public function test_close_clears_open_and_sets_duration(): void
    {
        $siteId = $this->makeSite(1, 'AcmeBlog');
        $repo = new IncidentsRepository();
        $id = $repo->open($siteId, '2026-06-14 10:00:00', 'x');
        $repo->close($id, '2026-06-14 10:35:00', 2100);
        $this->assertNull($repo->findOpenForSite($siteId));
        $rows = $repo->findForSite($siteId, 10, 0);
        $this->assertSame(2100, $rows[0]->durationSeconds);
    }

    public function test_mark_down_alert_sent_stamps_column(): void
    {
        $siteId = $this->makeSite(1, 'AcmeBlog');
        $repo = new IncidentsRepository();
        $id = $repo->open($siteId, '2026-06-14 10:00:00', 'x');
        $repo->markDownAlertSent($id, '2026-06-14 10:00:01');
        global $wpdb;
        $t = \Defyn\Dashboard\Schema\IncidentsTable::tableName();
        $val = $wpdb->get_var($wpdb->prepare("SELECT down_alert_sent_at FROM `{$t}` WHERE id = %d", $id));
        $this->assertSame('2026-06-14 10:00:01', $val);
    }

    public function test_find_for_site_newest_first_respects_limit_offset(): void
    {
        $siteId = $this->makeSite(1, 'AcmeBlog');
        $repo = new IncidentsRepository();
        $repo->open($siteId, '2026-06-10 10:00:00', 'old');
        $repo->open($siteId, '2026-06-14 10:00:00', 'new');
        $rows = $repo->findForSite($siteId, 1, 0);
        $this->assertCount(1, $rows);
        $this->assertSame('new', $rows[0]->lastError);
    }

    public function test_find_open_for_user_joins_label_and_returns_fleet_wide(): void
    {
        // Team-wide: per-user filter removed (2026-06-22 SSO spec).
        $mine   = $this->makeSite(1, 'Mine');
        $theirs = $this->makeSite(2, 'Theirs');
        $repo = new IncidentsRepository();
        $repo->open($mine, '2026-06-14 10:00:00', 'x');
        $repo->open($theirs, '2026-06-14 10:00:00', 'x');

        // Team-wide: both users see all sites fleet-wide.
        $rows = $repo->findOpenForUser(1);
        $this->assertCount(2, $rows);
        $labels = array_column($rows, 'site_label');
        $this->assertContains('Mine', $labels);
        $this->assertContains('Theirs', $labels);
    }

    public function testFindForUserSinceReturnsOverlappingAndOpenIncidents(): void
    {
        $mine  = $this->makeSite(1, 'M');
        $other = $this->makeSite(2, 'O');
        $repo  = new IncidentsRepository();

        // in-window closed (ended ~24h ago, well within 30-day window)
        $a = $repo->open($mine, gmdate('Y-m-d H:i:s', time() - 90_000), 'boom');
        $repo->close($a, gmdate('Y-m-d H:i:s', time() - 86_000), 4000);
        // open (ongoing)
        $repo->open($mine, gmdate('Y-m-d H:i:s', time() - 600), 'still down');
        // closed BEFORE the window (40 days ago) — must be excluded
        $c = $repo->open($mine, gmdate('Y-m-d H:i:s', time() - 3_500_000), 'old');
        $repo->close($c, gmdate('Y-m-d H:i:s', time() - 3_400_000), 100000);
        // Team-wide: per-user filter removed (2026-06-22 SSO spec).
        // Another owner's open incident — now included fleet-wide.
        $repo->open($other, gmdate('Y-m-d H:i:s', time() - 600), 'theirs');

        $since = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        $rows  = $repo->findForUserSince(1, $since);

        // Team-wide: both users see all sites fleet-wide — 3 in-window incidents.
        self::assertCount(3, $rows);
        self::assertArrayHasKey('started_at', $rows[0]);
        self::assertArrayHasKey('ended_at', $rows[0]);
    }

    public function testFindForUserSinceIsTeamWide(): void
    {
        // Team-wide: per-user filter removed (2026-06-22 SSO spec).
        // Seed incidents on user 11's site; call findForUserSince(22, ...) and assert they appear.
        $site11 = $this->makeSite(11, 'TeamSite');
        $repo   = new IncidentsRepository();

        $repo->open($site11, gmdate('Y-m-d H:i:s', time() - 3600), 'cross-owner incident');

        $since = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        $rows  = $repo->findForUserSince(22, $since);

        self::assertNotEmpty($rows, 'Cross-owner incidents must appear fleet-wide.');
        $siteIds = array_column($rows, 'site_id');
        self::assertContains($site11, $siteIds);
    }

    public function testFindOpenForUserIsTeamWide(): void
    {
        // Team-wide: per-user filter removed (2026-06-22 SSO spec).
        // Seed an open incident on user 11's site; call findOpenForUser(22) and assert it appears.
        $site11 = $this->makeSite(11, 'TeamSite');
        $repo   = new IncidentsRepository();
        $repo->open($site11, '2026-06-22 10:00:00', 'cross-owner open incident');

        $rows = $repo->findOpenForUser(22);
        self::assertCount(1, $rows);
        self::assertSame($site11, $rows[0]['site_id']);
    }
}
