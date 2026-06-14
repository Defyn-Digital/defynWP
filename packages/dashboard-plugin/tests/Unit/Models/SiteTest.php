<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\Site;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
final class SiteTest extends TestCase
{
    public function testFromRowMapsAllExpectedFields(): void
    {
        $row = [
            'id'              => '42',
            'user_id'         => '7',
            'url'             => 'https://example.test',
            'label'           => 'Test site',
            'status'          => 'pending',
            'site_public_key' => null,
            'our_public_key'  => 'PUBKEY==',
            'last_contact_at' => null,
            'last_error'      => null,
            'created_at'      => '2026-05-11 00:00:00',
        ];

        $site = Site::fromRow($row);

        self::assertSame(42, $site->id);
        self::assertSame(7, $site->userId);
        self::assertSame('https://example.test', $site->url);
        self::assertSame('Test site', $site->label);
        self::assertSame('pending', $site->status);
        self::assertNull($site->sitePublicKey);
        self::assertSame('PUBKEY==', $site->ourPublicKey);
        self::assertNull($site->lastContactAt);
        self::assertNull($site->lastError);
        self::assertSame('2026-05-11 00:00:00', $site->createdAt);
    }

    public function testFromRowMapsLastResponseTimeMs(): void
    {
        $site = \Defyn\Dashboard\Models\Site::fromRow([
            'id' => 1, 'user_id' => 1, 'url' => 'https://a.test', 'label' => 'A',
            'status' => 'active', 'created_at' => '2026-06-14 00:00:00',
            'last_response_time_ms' => '247',
        ]);
        self::assertSame(247, $site->lastResponseTimeMs);
    }

    public function testFromRowDefaultsLastResponseTimeMsToNull(): void
    {
        $site = \Defyn\Dashboard\Models\Site::fromRow([
            'id' => 1, 'user_id' => 1, 'url' => 'https://a.test', 'label' => 'A',
            'status' => 'active', 'created_at' => '2026-06-14 00:00:00',
        ]);
        self::assertNull($site->lastResponseTimeMs);
    }

    public function testToJsonProducesSpaShape(): void
    {
        $site = Site::fromRow([
            'id'              => '1',
            'user_id'         => '1',
            'url'             => 'https://example.test',
            'label'           => '',
            'status'          => 'active',
            'site_public_key' => 'SITEPUB==',
            'our_public_key'  => 'OURPUB==',
            'last_contact_at' => '2026-05-11 00:07:00',
            'last_error'      => null,
            'created_at'      => '2026-05-11 00:00:00',
        ]);

        self::assertSame([
            'id'              => 1,
            'url'             => 'https://example.test',
            'label'           => '',
            'status'          => 'active',
            'last_contact_at' => '2026-05-11 00:07:00',
            'last_sync_at'    => null,
            'last_error'      => null,
            'created_at'      => '2026-05-11 00:00:00',
            // F8: runtime fields default to null when the row hasn't synced.
            'wp_version'      => null,
            'php_version'     => null,
            'active_theme'    => null,
            'plugin_counts'   => null,
            'theme_counts'    => null,
            'ssl_status'      => null,
            'ssl_expires_at'  => null,
            // P2.4: core update fields default to idle/false/null when not set.
            'core_update_available'       => false,
            'core_update_version'         => null,
            'core_update_state'           => 'idle',
            'last_core_update_error'      => null,
            'last_core_update_attempt_at' => null,
            // P2.4.1: major version policy flag defaults to false.
            'core_allow_major'            => false,
        ], $site->toJson());
    }
}
