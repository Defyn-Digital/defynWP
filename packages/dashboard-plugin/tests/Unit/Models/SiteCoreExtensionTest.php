<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\Site;
use PHPUnit\Framework\TestCase;

final class SiteCoreExtensionTest extends TestCase
{
    public function testFromRowMapsCoreUpdateColumns(): void
    {
        $row = $this->baseRow() + [
            'core_update_available'       => '1',
            'core_update_version'         => '7.0.1',
            'core_update_state'           => 'idle',
            'last_core_update_error'      => null,
            'last_core_update_attempt_at' => '2026-06-07 04:00:00',
        ];

        $site = Site::fromRow($row);

        $this->assertTrue($site->coreUpdateAvailable);
        $this->assertSame('7.0.1', $site->coreUpdateVersion);
        $this->assertSame('idle', $site->coreUpdateState);
        $this->assertNull($site->lastCoreUpdateError);
        $this->assertSame('2026-06-07 04:00:00', $site->lastCoreUpdateAttemptAt);
    }

    public function testFromRowDefaultsWhenColumnsMissing(): void
    {
        $row = $this->baseRow();

        $site = Site::fromRow($row);

        $this->assertFalse($site->coreUpdateAvailable);
        $this->assertNull($site->coreUpdateVersion);
        $this->assertSame('idle', $site->coreUpdateState);
        $this->assertNull($site->lastCoreUpdateError);
        $this->assertNull($site->lastCoreUpdateAttemptAt);
    }

    public function testToJsonExposesCoreUpdateFieldsInSnakeCase(): void
    {
        $row = $this->baseRow() + [
            'core_update_available'       => '0',
            'core_update_version'         => null,
            'core_update_state'           => 'failed',
            'last_core_update_error'      => 'Disk full',
            'last_core_update_attempt_at' => '2026-06-07 04:00:00',
        ];

        $json = Site::fromRow($row)->toJson();

        $this->assertFalse($json['core_update_available']);
        $this->assertNull($json['core_update_version']);
        $this->assertSame('failed', $json['core_update_state']);
        $this->assertSame('Disk full', $json['last_core_update_error']);
        $this->assertSame('2026-06-07 04:00:00', $json['last_core_update_attempt_at']);
    }

    /** @return array<string, mixed> */
    private function baseRow(): array
    {
        return [
            'id'              => '7',
            'user_id'         => '1',
            'url'             => 'https://smartcoding.test',
            'label'           => 'Smart',
            'status'          => 'active',
            'site_public_key' => null,
            'our_public_key'  => null,
            'last_contact_at' => '2026-06-07 04:00:00',
            'last_error'      => null,
            'created_at'      => '2026-06-06 00:00:00',
            'our_private_key' => null,
            'wp_version'      => '7.0',
            'php_version'     => '8.3.31',
            'plugin_counts'   => '{"installed":21,"active":20}',
            'theme_counts'    => '{"installed":8,"active":1}',
            'ssl_status'      => 'enabled',
            'ssl_expires_at'  => null,
            'last_sync_at'    => '2026-06-07 04:00:00',
        ];
    }
}
