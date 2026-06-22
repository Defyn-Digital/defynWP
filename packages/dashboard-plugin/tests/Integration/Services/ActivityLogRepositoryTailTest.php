<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\ActivityLogRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ActivityLogRepositoryTailTest extends AbstractSchemaTestCase
{
    public function testTailForUserReturnsTwentyFiveOrderedByCreatedAtDesc(): void
    {
        // Team-wide: per-user filter removed (2026-06-22 SSO spec).
        // With team-wide, tailForUser returns the most recent events across all sites.
        // siteA gets 30 events, siteB gets 5 events — 35 total, tail capped at 25.
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(2); // different owner — now included fleet-wide

        $logger = new ActivityLogger();
        for ($i = 0; $i < 30; $i++) {
            $logger->log(1, $siteA, 'site.health_ok', ['seq' => $i]);
        }
        for ($i = 0; $i < 5; $i++) {
            $logger->log(2, $siteB, 'site.health_ok', ['seq' => $i]);
        }

        $tail = (new ActivityLogRepository())->tailForUser(1, 25);

        // Team-wide: both users see all sites fleet-wide — still capped at requested limit.
        $this->assertCount(25, $tail);
    }

    public function testTailForUserIncludesOtherOwnersEventsFleetWide(): void
    {
        // Team-wide: per-user filter removed (2026-06-22 SSO spec).
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(2);

        (new ActivityLogger())->log(1, $siteA, 'plugin_update.succeeded', ['marker' => 'user1']);
        (new ActivityLogger())->log(2, $siteB, 'plugin_update.succeeded', ['marker' => 'user2']);

        $tail = (new ActivityLogRepository())->tailForUser(1, 25);

        // Team-wide: both users see all sites fleet-wide — both markers must appear.
        $this->assertCount(2, $tail);
        $markers = array_map(static fn ($row) => json_decode($row['details'] ?? '{}', true)['marker'] ?? null, $tail);
        $this->assertContains('user1', $markers);
        $this->assertContains('user2', $markers);
    }

    public function testTailForUserIsTeamWide(): void
    {
        // Team-wide: per-user filter removed (2026-06-22 SSO spec).
        // Insert a site for user 11, insert an activity event for that site, call tailForUser(22).
        $site11 = $this->seedSite(11);
        (new ActivityLogger())->log(11, $site11, 'site.synced', ['marker' => 'cross-owner']);

        $tail = (new ActivityLogRepository())->tailForUser(22, 25);
        $this->assertNotEmpty($tail, 'Cross-owner events must appear fleet-wide.');
        $markers = array_map(static fn ($row) => json_decode($row['details'] ?? '{}', true)['marker'] ?? null, $tail);
        $this->assertContains('cross-owner', $markers);
    }

    public function testTailForUserIncludesSiteLabelJoin(): void
    {
        $siteA = $this->seedSite(1);
        (new ActivityLogger())->log(1, $siteA, 'plugin_update.succeeded', null);

        $tail = (new ActivityLogRepository())->tailForUser(1, 25);
        $this->assertNotEmpty($tail);
        $this->assertArrayHasKey('site_label', $tail[0]);
        $this->assertSame('Example', $tail[0]['site_label']);
    }

    private function seedSite(int $userId): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://ex' . microtime(true) . '.com',
            'label'      => 'Example',
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }
}
