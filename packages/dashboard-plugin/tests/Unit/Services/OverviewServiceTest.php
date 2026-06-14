<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Services;

use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\IncidentsRepository;
use Defyn\Dashboard\Services\OverviewService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class OverviewServiceTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        global $wpdb;
        // Purge custom tables BEFORE the parent starts its transaction so a
        // pre-existing fixture row doesn't bleed into total_sites assertions.
        // Mirrors SitesRepositoryOverviewTest::setUp() from P2.5 Task 1.
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_activity_log");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_incidents");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_plugins");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_themes");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");
        // phpcs:enable WordPress.DB.PreparedSQL
        parent::setUp();
    }

    public function testComposeReturnsFullEnvelopeShape(): void
    {
        $this->seedSite(1);
        $result = (new OverviewService())->compose(1);

        $this->assertArrayHasKey('pending_updates', $result);
        $this->assertArrayHasKey('sites_needing_attention', $result);
        $this->assertArrayHasKey('recent_activity', $result);
        $this->assertArrayHasKey('generated_at', $result);

        $this->assertArrayHasKey('plugins', $result['pending_updates']);
        $this->assertArrayHasKey('themes', $result['pending_updates']);
        $this->assertArrayHasKey('cores_minor', $result['pending_updates']);
        $this->assertArrayHasKey('cores_major', $result['pending_updates']);
        $this->assertArrayHasKey('sites_with_any_update', $result['pending_updates']);
    }

    public function testComposeIncludesOfflineSiteInAttention(): void
    {
        global $wpdb;
        $siteA = $this->seedSite(1);
        $wpdb->update($wpdb->prefix . 'defyn_sites', [
            'last_contact_at' => gmdate('Y-m-d H:i:s', strtotime('-20 minutes')),
        ], ['id' => $siteA]);

        $result = (new OverviewService())->compose(1);
        $this->assertNotEmpty($result['sites_needing_attention']);
        $this->assertContains('offline', $result['sites_needing_attention'][0]['reasons']);
    }

    public function testComposeIncludesFailedUpdateInAttention(): void
    {
        global $wpdb;
        $siteA = $this->seedSite(1);
        $wpdb->update($wpdb->prefix . 'defyn_sites', [
            'core_update_state' => 'failed',
            'last_contact_at'   => gmdate('Y-m-d H:i:s'),
            'last_sync_at'      => gmdate('Y-m-d H:i:s'),
            'ssl_expires_at'    => gmdate('Y-m-d H:i:s', strtotime('+90 days')),
        ], ['id' => $siteA]);

        $result = (new OverviewService())->compose(1);
        $this->assertContains('failed_update', $result['sites_needing_attention'][0]['reasons']);
    }

    public function testComposeIncludesRecentActivity(): void
    {
        $siteA = $this->seedSite(1);
        (new ActivityLogger())->log(1, $siteA, 'plugin_update.succeeded', ['slug' => 'akismet']);

        $result = (new OverviewService())->compose(1);
        $this->assertNotEmpty($result['recent_activity']);
        $this->assertSame('plugin_update.succeeded', $result['recent_activity'][0]['event_type']);
    }

    public function testGeneratedAtIsServerTimestampString(): void
    {
        $this->seedSite(1);
        $result = (new OverviewService())->compose(1);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $result['generated_at']
        );
    }

    public function testComposeIncludesTotalSitesCount(): void
    {
        $this->seedSite(1);
        $this->seedSite(1);
        $this->seedSite(1);
        $this->seedSite(2); // other user's site

        $result = (new OverviewService())->compose(1);

        $this->assertArrayHasKey('total_sites', $result);
        $this->assertSame(3, $result['total_sites']);
    }

    public function testComposeIncludesOpenIncidentsForUser(): void
    {
        $siteId    = $this->seedSite(1);
        $startedAt = gmdate('Y-m-d H:i:s', strtotime('-5 minutes'));
        (new IncidentsRepository())->open($siteId, $startedAt, 'connect timeout');

        $result = (new OverviewService())->compose(1);

        $this->assertArrayHasKey('open_incidents', $result);
        $this->assertCount(1, $result['open_incidents']);
        $this->assertSame($siteId, $result['open_incidents'][0]['site_id']);
        $this->assertSame('Example', $result['open_incidents'][0]['site_label']);
        $this->assertSame($startedAt, $result['open_incidents'][0]['started_at']);
    }

    public function testComposeReturnsEmptyOpenIncidentsWhenNone(): void
    {
        $this->seedSite(1);

        $result = (new OverviewService())->compose(1);

        $this->assertArrayHasKey('open_incidents', $result);
        $this->assertSame([], $result['open_incidents']);
    }

    private function seedSite(int $userId): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'         => $userId,
            'url'             => 'https://ex' . microtime(true) . '.com',
            'label'           => 'Example',
            'status'          => 'active',
            'created_at'      => gmdate('Y-m-d H:i:s'),
            'last_contact_at' => gmdate('Y-m-d H:i:s'),
            'last_sync_at'    => gmdate('Y-m-d H:i:s'),
            'ssl_expires_at'  => gmdate('Y-m-d H:i:s', strtotime('+90 days')),
        ]);
        return (int) $wpdb->insert_id;
    }
}
