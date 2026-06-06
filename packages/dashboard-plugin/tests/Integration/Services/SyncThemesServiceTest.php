<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\SiteThemesTable;
use Defyn\Dashboard\Services\SyncThemesService;
use Defyn\Dashboard\Services\ThemesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SyncThemesServiceTest extends AbstractSchemaTestCase
{
    private SyncThemesService $service;
    private ThemesRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::activate();
        $this->repo = new ThemesRepository();
        $this->service = new SyncThemesService($this->repo);
        $this->clearTableForTestSite();
    }

    private function clearTableForTestSite(): void
    {
        global $wpdb;
        $wpdb->delete(SiteThemesTable::tableName(), ['site_id' => 7], ['%d']);
    }

    public function testSyncPersistsThemesAndLogsEvent(): void
    {
        $payload = [
            'themes' => [
                $this->themeRow('twentytwentyfive', 'Twenty Twenty-Five', '1.2', null, true, true, '1.3'),
                $this->themeRow('astra', 'Astra', '4.5', null, false, false, null),
            ],
        ];

        $this->service->sync(7, $payload, 'background');

        $rows = $this->repo->findAllForSite(7);
        $this->assertCount(2, $rows);

        global $wpdb;
        $event = $wpdb->get_row(
            "SELECT event_type, details FROM {$wpdb->prefix}defyn_activity_log
             WHERE site_id = 7 AND event_type = 'theme_inventory.synced'
             ORDER BY id DESC LIMIT 1",
            ARRAY_A,
        );
        $this->assertNotNull($event);
        $details = json_decode((string) $event['details'], true);
        $this->assertSame(2, $details['theme_count']);
        $this->assertSame(1, $details['updates_available_count']);
        $this->assertSame('background', $details['source']);
    }

    public function testSyncWithEmptyListClearsRowsAndLogsZero(): void
    {
        $this->service->sync(7, ['themes' => [
            $this->themeRow('twentytwentyfive', 'Twenty Twenty-Five', '1.2', null, true, false, null),
        ]], 'background');
        $this->assertCount(1, $this->repo->findAllForSite(7));

        $this->service->sync(7, ['themes' => []], 'background');
        $this->assertCount(0, $this->repo->findAllForSite(7));
    }

    public function testSyncHealsDanglingFailedRowWhenUpdateNoLongerAvailable(): void
    {
        global $wpdb;
        $wpdb->insert(SiteThemesTable::tableName(), [
            'site_id' => 7, 'slug' => 'twentytwentyfive', 'name' => 'Twenty Twenty-Five',
            'version' => '1.2', 'parent_slug' => null, 'is_active' => 1,
            'update_available' => 1, 'update_version' => '1.3',
            'update_state' => 'failed', 'last_update_error' => 'old error',
            'last_update_attempt_at' => '2026-06-06 08:00:00',
            'last_seen_at' => '2026-06-06 05:00:00',
            'created_at' => '2026-06-06 05:00:00', 'updated_at' => '2026-06-06 05:00:00',
        ]);

        $this->service->sync(7, ['themes' => [
            $this->themeRow('twentytwentyfive', 'Twenty Twenty-Five', '1.3', null, true, false, null),
        ]], 'background');

        $row = $this->repo->findRowForSiteAndSlug(7, 'twentytwentyfive');
        $this->assertSame('idle', $row['update_state']);
        $this->assertNull($row['last_update_error']);
        $this->assertSame('1.3', $row['version']);
    }

    public function testSyncDoesNotHealRowsWithActiveUpdate(): void
    {
        global $wpdb;
        $wpdb->insert(SiteThemesTable::tableName(), [
            'site_id' => 7, 'slug' => 'astra', 'name' => 'Astra',
            'version' => '4.5', 'parent_slug' => null, 'is_active' => 0,
            'update_available' => 1, 'update_version' => '4.6',
            'update_state' => 'failed', 'last_update_error' => 'real prior failure',
            'last_update_attempt_at' => '2026-06-06 08:00:00',
            'last_seen_at' => '2026-06-06 05:00:00',
            'created_at' => '2026-06-06 05:00:00', 'updated_at' => '2026-06-06 05:00:00',
        ]);

        $this->service->sync(7, ['themes' => [
            $this->themeRow('astra', 'Astra', '4.5', null, false, true, '4.6'),
        ]], 'background');

        $row = $this->repo->findRowForSiteAndSlug(7, 'astra');
        $this->assertSame('failed', $row['update_state']);
        $this->assertSame('real prior failure', $row['last_update_error']);
    }

    public function testSyncCorrectlyMarksActiveTheme(): void
    {
        $this->service->sync(7, ['themes' => [
            $this->themeRow('twentytwentyfive', 'Twenty Twenty-Five', '1.2', null, true, false, null),
            $this->themeRow('astra', 'Astra', '4.5', null, false, false, null),
        ]], 'background');

        $this->service->sync(7, ['themes' => [
            $this->themeRow('twentytwentyfive', 'Twenty Twenty-Five', '1.2', null, false, false, null),
            $this->themeRow('astra', 'Astra', '4.5', null, true, false, null),
        ]], 'background');

        $bySlug = [];
        foreach ($this->repo->findAllForSite(7) as $row) {
            $bySlug[$row->slug] = $row;
        }
        $this->assertTrue($bySlug['astra']->isActive);
        $this->assertFalse($bySlug['twentytwentyfive']->isActive);
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
            'slug' => $slug, 'name' => $name, 'version' => $version,
            'parent_slug' => $parentSlug, 'is_active' => $isActive,
            'update_available' => $updateAvailable, 'update_version' => $updateVersion,
        ];
    }
}
