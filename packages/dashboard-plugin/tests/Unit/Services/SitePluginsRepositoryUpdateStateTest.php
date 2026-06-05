<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Services;

use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitePluginsRepositoryUpdateStateTest extends AbstractSchemaTestCase
{
    private SitePluginsRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_site_plugins');
        $this->repo = new SitePluginsRepository();

        global $wpdb;
        $wpdb->insert(SitePluginsTable::tableName(), [
            'site_id'          => 7,
            'slug'             => 'akismet',
            'name'             => 'Akismet',
            'version'          => '5.7',
            'update_available' => 1,
            'update_version'   => '5.8',
            'update_state'     => 'idle',
            'last_seen_at'     => '2026-06-06 00:00:00',
            'created_at'       => '2026-06-06 00:00:00',
            'updated_at'       => '2026-06-06 00:00:00',
        ]);
    }

    public function testFindRowForSiteAndSlugReturnsRow(): void
    {
        $row = $this->repo->findRowForSiteAndSlug(7, 'akismet');
        $this->assertNotNull($row);
        $this->assertSame('akismet', $row['slug']);
        $this->assertSame('5.7', $row['version']);
        $this->assertSame('1', $row['update_available']);
    }

    public function testFindRowForSiteAndSlugReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repo->findRowForSiteAndSlug(7, 'not-there'));
        $this->assertNull($this->repo->findRowForSiteAndSlug(99, 'akismet'));
    }

    public function testMarkUpdateRequestedSetsQueuedAndClearsError(): void
    {
        global $wpdb;
        $wpdb->update(
            SitePluginsTable::tableName(),
            ['update_state' => 'failed', 'last_update_error' => 'old error'],
            ['site_id' => 7, 'slug' => 'akismet']
        );

        $this->repo->markUpdateRequested(7, 'akismet', '2026-06-06 09:00:00');
        $row = $this->repo->findRowForSiteAndSlug(7, 'akismet');

        $this->assertSame('queued', $row['update_state']);
        $this->assertNull($row['last_update_error']);
        $this->assertSame('2026-06-06 09:00:00', $row['last_update_attempt_at']);
    }

    public function testMarkUpdatingFlipsState(): void
    {
        $this->repo->markUpdating(7, 'akismet', '2026-06-06 09:00:30');
        $row = $this->repo->findRowForSiteAndSlug(7, 'akismet');
        $this->assertSame('updating', $row['update_state']);
    }

    public function testMarkUpdateSucceededClearsBadgeAndBumpsVersion(): void
    {
        $this->repo->markUpdateSucceeded(7, 'akismet', '5.8', '2026-06-06 09:01:00');
        $row = $this->repo->findRowForSiteAndSlug(7, 'akismet');

        $this->assertSame('idle', $row['update_state']);
        $this->assertSame('5.8', $row['version']);
        $this->assertSame('0', $row['update_available']);
        $this->assertNull($row['update_version']);
        $this->assertNull($row['last_update_error']);
    }

    public function testMarkUpdateFailedTruncatesLongError(): void
    {
        $long = str_repeat('A', 1200);
        $this->repo->markUpdateFailed(7, 'akismet', $long, '2026-06-06 09:01:00');
        $row = $this->repo->findRowForSiteAndSlug(7, 'akismet');

        $this->assertSame('failed', $row['update_state']);
        $this->assertSame(1000, strlen($row['last_update_error']));
        $this->assertSame('2026-06-06 09:01:00', $row['last_update_attempt_at']);
    }
}
