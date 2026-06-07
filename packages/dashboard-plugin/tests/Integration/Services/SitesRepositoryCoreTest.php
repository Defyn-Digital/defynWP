<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesRepositoryCoreTest extends AbstractSchemaTestCase
{
    private SitesRepository $repo;
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::activate();
        $this->repo = new SitesRepository();

        global $wpdb;
        $wpdb->insert(SitesTable::tableName(), [
            'user_id'         => 1,
            'url'             => 'https://smartcoding.test',
            'label'           => 'Smart',
            'status'          => 'active',
            'our_private_key' => '',
            'wp_version'      => '7.0',
            'php_version'     => '8.3.31',
            'plugin_counts'   => '{"installed":0,"active":0}',
            'theme_counts'    => '{"installed":0,"active":0}',
            'ssl_status'      => 'enabled',
            'ssl_expires_at'  => null,
            'last_sync_at'    => '2026-06-07 04:00:00',
            'last_contact_at' => '2026-06-07 04:00:00',
            'created_at'      => '2026-06-06 00:00:00',
            'updated_at'      => '2026-06-07 04:00:00',
        ]);
        $this->siteId = (int) $wpdb->insert_id;
    }

    public function testMarkCoreUpdateRequestedSetsQueuedAndClearsError(): void
    {
        $this->seedRowState('failed', '7.0.1', 1, 'old error');

        $this->repo->markCoreUpdateRequested($this->siteId, '2026-06-07 09:00:00');
        $row = $this->findRow();

        $this->assertSame('queued', $row['core_update_state']);
        $this->assertNull($row['last_core_update_error']);
        $this->assertSame('2026-06-07 09:00:00', $row['last_core_update_attempt_at']);
    }

    public function testMarkCoreUpdatingFlipsState(): void
    {
        $this->seedRowState('queued', '7.0.1', 1, null);

        $this->repo->markCoreUpdating($this->siteId, '2026-06-07 09:00:30');
        $row = $this->findRow();
        $this->assertSame('updating', $row['core_update_state']);
    }

    public function testMarkCoreUpdateSucceededBumpsVersionAndClearsAvailable(): void
    {
        $this->seedRowState('updating', '7.0.1', 1, null);

        $this->repo->markCoreUpdateSucceeded($this->siteId, '7.0.1', '2026-06-07 09:01:00');
        $row = $this->findRow();

        $this->assertSame('idle', $row['core_update_state']);
        $this->assertSame('7.0.1', $row['wp_version']);
        $this->assertSame('0', $row['core_update_available']);
        $this->assertNull($row['core_update_version']);
        $this->assertNull($row['last_core_update_error']);
    }

    public function testMarkCoreUpdateFailedTruncatesLongError(): void
    {
        $this->seedRowState('updating', '7.0.1', 1, null);

        $long = str_repeat('A', 1200);
        $this->repo->markCoreUpdateFailed($this->siteId, $long, '2026-06-07 09:01:00');
        $row = $this->findRow();

        $this->assertSame('failed', $row['core_update_state']);
        $this->assertSame(1000, strlen($row['last_core_update_error']));
        $this->assertSame('2026-06-07 09:01:00', $row['last_core_update_attempt_at']);
    }

    public function testMarkSyncedPropagatesCoreFieldsFromStatusPayload(): void
    {
        $this->repo->markSynced($this->siteId, $this->statusPayload([
            'core' => [
                'update_available'       => true,
                'update_version'         => '7.0.1',
                'is_minor_update'        => true,
                'is_auto_update_enabled' => false,
            ],
        ]));

        $row = $this->findRow();
        $this->assertSame('1', $row['core_update_available']);
        $this->assertSame('7.0.1', $row['core_update_version']);
    }

    public function testMarkSyncedHealsStuckFailedWhenIncomingHasNoUpdateAvailable(): void
    {
        $this->seedRowState('failed', '7.0.1', 1, 'lingering error');

        $this->repo->markSynced($this->siteId, $this->statusPayload([
            'core' => [
                'update_available'       => false,
                'update_version'         => null,
                'is_minor_update'        => false,
                'is_auto_update_enabled' => true,
            ],
        ]));

        $row = $this->findRow();
        $this->assertSame('idle', $row['core_update_state']);
        $this->assertNull($row['last_core_update_error']);
        $this->assertSame('0', $row['core_update_available']);
    }

    public function testMarkSyncedDoesNotHealWhenUpdateStillAvailable(): void
    {
        $this->seedRowState('failed', '7.0.1', 1, 'real prior failure');

        $this->repo->markSynced($this->siteId, $this->statusPayload([
            'core' => [
                'update_available'       => true,
                'update_version'         => '7.0.1',
                'is_minor_update'        => true,
                'is_auto_update_enabled' => true,
            ],
        ]));

        $row = $this->findRow();
        $this->assertSame('failed', $row['core_update_state']);
        $this->assertSame('real prior failure', $row['last_core_update_error']);
    }

    /** @return array<string, string|null> */
    private function findRow(): array
    {
        global $wpdb;
        return (array) $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . SitesTable::tableName() . " WHERE id = %d", $this->siteId),
            ARRAY_A,
        );
    }

    private function seedRowState(string $state, ?string $version, int $available, ?string $error): void
    {
        global $wpdb;
        $wpdb->update(SitesTable::tableName(), [
            'core_update_state'      => $state,
            'core_update_version'    => $version,
            'core_update_available'  => $available,
            'last_core_update_error' => $error,
        ], ['id' => $this->siteId]);
    }

    /** @param array<string, mixed> $overrides */
    private function statusPayload(array $overrides): array
    {
        return array_merge([
            'wp_version'     => '7.0',
            'php_version'    => '8.3.31',
            'plugin_counts'  => ['installed' => 0, 'active' => 0],
            'theme_counts'   => ['installed' => 0, 'active' => 0],
            'ssl_status'     => 'enabled',
            'ssl_expires_at' => null,
        ], $overrides);
    }
}
