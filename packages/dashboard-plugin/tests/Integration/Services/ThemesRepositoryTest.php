<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\SiteThemesTable;
use Defyn\Dashboard\Services\ThemesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ThemesRepositoryTest extends AbstractSchemaTestCase
{
    private ThemesRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::activate();
        $this->repo = new ThemesRepository();
        $this->clearTableForTestSite();
    }

    private function clearTableForTestSite(): void
    {
        global $wpdb;
        $wpdb->delete(SiteThemesTable::tableName(), ['site_id' => 7], ['%d']);
    }

    public function testReplaceForSiteInsertsRows(): void
    {
        $now = '2026-06-06 05:00:00';
        $this->repo->replaceForSite(7, [
            $this->themeRow('twentytwentyfive', 'Twenty Twenty-Five', '1.2', null, true, true, '1.3'),
            $this->themeRow('astra', 'Astra', '4.5', null, false, false, null),
            $this->themeRow('astra-child', 'Astra Child', '1.0.0', 'astra', false, false, null),
        ], $now);

        $rows = $this->repo->findAllForSite(7);
        $this->assertCount(3, $rows);

        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[$row->slug] = $row;
        }

        $this->assertTrue($bySlug['twentytwentyfive']->isActive);
        $this->assertNull($bySlug['twentytwentyfive']->parentSlug);
        $this->assertTrue($bySlug['twentytwentyfive']->updateAvailable);
        $this->assertSame('astra', $bySlug['astra-child']->parentSlug);
        $this->assertFalse($bySlug['astra-child']->isActive);
    }

    public function testReplaceForSiteFlipsActiveStylesheet(): void
    {
        $now1 = '2026-06-06 05:00:00';
        $this->repo->replaceForSite(7, [
            $this->themeRow('twentytwentyfive', 'Twenty Twenty-Five', '1.2', null, true, false, null),
            $this->themeRow('astra', 'Astra', '4.5', null, false, false, null),
        ], $now1);

        $now2 = '2026-06-06 06:00:00';
        $this->repo->replaceForSite(7, [
            $this->themeRow('twentytwentyfive', 'Twenty Twenty-Five', '1.2', null, false, false, null),
            $this->themeRow('astra', 'Astra', '4.5', null, true, false, null),
        ], $now2);

        $bySlug = [];
        foreach ($this->repo->findAllForSite(7) as $row) {
            $bySlug[$row->slug] = $row;
        }

        $this->assertFalse($bySlug['twentytwentyfive']->isActive);
        $this->assertTrue($bySlug['astra']->isActive);
    }

    public function testReplaceForSiteDeletesRemovedRows(): void
    {
        $now1 = '2026-06-06 05:00:00';
        $this->repo->replaceForSite(7, [
            $this->themeRow('twentytwentyfive', 'Twenty Twenty-Five', '1.2', null, true, false, null),
            $this->themeRow('astra', 'Astra', '4.5', null, false, false, null),
        ], $now1);

        $now2 = '2026-06-06 06:00:00';
        $this->repo->replaceForSite(7, [
            $this->themeRow('twentytwentyfive', 'Twenty Twenty-Five', '1.2', null, true, false, null),
        ], $now2);

        $this->assertCount(1, $this->repo->findAllForSite(7));
    }

    public function testLastSyncedAtForSite(): void
    {
        $this->repo->replaceForSite(7, [
            $this->themeRow('twentytwentyfive', 'Twenty Twenty-Five', '1.2', null, true, false, null),
        ], '2026-06-06 05:00:00');
        $this->assertSame('2026-06-06 05:00:00', $this->repo->lastSyncedAtForSite(7));

        $this->assertNull($this->repo->lastSyncedAtForSite(99999));
    }

    public function testFindRowForSiteAndSlug(): void
    {
        $this->repo->replaceForSite(7, [
            $this->themeRow('twentytwentyfive', 'Twenty Twenty-Five', '1.2', null, true, true, '1.3'),
        ], '2026-06-06 05:00:00');

        $row = $this->repo->findRowForSiteAndSlug(7, 'twentytwentyfive');
        $this->assertNotNull($row);
        $this->assertSame('twentytwentyfive', $row['slug']);
        $this->assertSame('1', $row['update_available']);
        $this->assertNull($this->repo->findRowForSiteAndSlug(7, 'not-there'));
        $this->assertNull($this->repo->findRowForSiteAndSlug(99, 'twentytwentyfive'));
    }

    public function testMarkUpdateRequestedSetsQueuedAndClearsError(): void
    {
        $this->seedFailedRow(7, 'twentytwentyfive', '1.2', '1.3', 'old error');

        $this->repo->markUpdateRequested(7, 'twentytwentyfive', '2026-06-06 09:00:00');
        $row = $this->repo->findRowForSiteAndSlug(7, 'twentytwentyfive');

        $this->assertSame('queued', $row['update_state']);
        $this->assertNull($row['last_update_error']);
        $this->assertSame('2026-06-06 09:00:00', $row['last_update_attempt_at']);
    }

    public function testMarkUpdatingFlipsState(): void
    {
        $this->repo->replaceForSite(7, [
            $this->themeRow('twentytwentyfive', 'Twenty Twenty-Five', '1.2', null, true, true, '1.3'),
        ], '2026-06-06 05:00:00');
        $this->repo->markUpdateRequested(7, 'twentytwentyfive', '2026-06-06 09:00:00');

        $this->repo->markUpdating(7, 'twentytwentyfive', '2026-06-06 09:00:30');
        $row = $this->repo->findRowForSiteAndSlug(7, 'twentytwentyfive');
        $this->assertSame('updating', $row['update_state']);
    }

    public function testMarkUpdateSucceededClearsBadgeAndBumpsVersion(): void
    {
        $this->repo->replaceForSite(7, [
            $this->themeRow('twentytwentyfive', 'Twenty Twenty-Five', '1.2', null, true, true, '1.3'),
        ], '2026-06-06 05:00:00');

        $this->repo->markUpdateSucceeded(7, 'twentytwentyfive', '1.3', '2026-06-06 09:01:00');
        $row = $this->repo->findRowForSiteAndSlug(7, 'twentytwentyfive');

        $this->assertSame('idle', $row['update_state']);
        $this->assertSame('1.3', $row['version']);
        $this->assertSame('0', $row['update_available']);
        $this->assertNull($row['update_version']);
        $this->assertNull($row['last_update_error']);
    }

    public function testMarkUpdateFailedTruncatesLongError(): void
    {
        $this->repo->replaceForSite(7, [
            $this->themeRow('twentytwentyfive', 'Twenty Twenty-Five', '1.2', null, true, true, '1.3'),
        ], '2026-06-06 05:00:00');

        $long = str_repeat('A', 1200);
        $this->repo->markUpdateFailed(7, 'twentytwentyfive', $long, '2026-06-06 09:01:00');
        $row = $this->repo->findRowForSiteAndSlug(7, 'twentytwentyfive');

        $this->assertSame('failed', $row['update_state']);
        $this->assertSame(1000, strlen($row['last_update_error']));
        $this->assertSame('2026-06-06 09:01:00', $row['last_update_attempt_at']);
    }

    public function testHealDanglingFailedStatesResetsRowsWhereNoUpdateAvailable(): void
    {
        $this->seedFailedRow(7, 'twentytwentyfive', '1.3', null, 'lingering error', updateAvailable: 0);
        $this->seedFailedRow(7, 'astra', '4.5', '4.6', 'real prior failure', updateAvailable: 1);

        $count = $this->repo->healDanglingFailedStates(7, '2026-06-06 09:30:00');
        $this->assertSame(1, $count);

        $healed = $this->repo->findRowForSiteAndSlug(7, 'twentytwentyfive');
        $this->assertSame('idle', $healed['update_state']);
        $this->assertNull($healed['last_update_error']);

        $stillFailed = $this->repo->findRowForSiteAndSlug(7, 'astra');
        $this->assertSame('failed', $stillFailed['update_state']);
        $this->assertSame('real prior failure', $stillFailed['last_update_error']);
    }

    /** @return array{slug:string,name:string,version:?string,parent_slug:?string,is_active:bool,update_available:bool,update_version:?string} */
    private function themeRow(
        string $slug,
        string $name,
        ?string $version,
        ?string $parentSlug,
        bool $isActive,
        bool $updateAvailable,
        ?string $updateVersion,
    ): array {
        return [
            'slug'             => $slug,
            'name'             => $name,
            'version'          => $version,
            'parent_slug'      => $parentSlug,
            'is_active'        => $isActive,
            'update_available' => $updateAvailable,
            'update_version'   => $updateVersion,
        ];
    }

    private function seedFailedRow(
        int $siteId,
        string $slug,
        string $version,
        ?string $updateVersion,
        string $error,
        int $updateAvailable = 1,
    ): void {
        global $wpdb;
        $wpdb->insert(SiteThemesTable::tableName(), [
            'site_id'                => $siteId,
            'slug'                   => $slug,
            'name'                   => ucfirst($slug),
            'version'                => $version,
            'parent_slug'            => null,
            'is_active'              => 0,
            'update_available'       => $updateAvailable,
            'update_version'         => $updateVersion,
            'update_state'           => 'failed',
            'last_update_error'      => $error,
            'last_update_attempt_at' => '2026-06-06 08:00:00',
            'last_seen_at'           => '2026-06-06 05:00:00',
            'created_at'             => '2026-06-06 05:00:00',
            'updated_at'             => '2026-06-06 05:00:00',
        ]);
    }
}
