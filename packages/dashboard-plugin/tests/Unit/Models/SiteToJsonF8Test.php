<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\Site;
use PHPUnit\Framework\TestCase;

/**
 * F8: Site::toJson() must expose F6/F7 runtime info (wp/php version,
 * active theme, plugin/theme counts, SSL status, last_sync_at) to the
 * SPA detail view, while STILL hiding the sensitive columns
 * (user_id, our_public_key, our_private_key, site_public_key).
 *
 * @group unit
 */
final class SiteToJsonF8Test extends TestCase
{
    public function testToJsonExposesAllRuntimeInfo(): void
    {
        $site = Site::fromRow([
            'id'              => 7,
            'user_id'         => 1,
            'url'             => 'https://x.test',
            'label'           => 'X',
            'status'          => 'active',
            'our_public_key'  => 'pub',
            'our_private_key' => 'priv-cipher',
            'site_public_key' => 'sitepub',
            'last_contact_at' => '2026-05-31 00:00:01',
            'last_sync_at'    => '2026-05-31 00:00:02',
            'last_error'      => '',
            'created_at'      => '2026-05-01 00:00:00',
            'updated_at'      => '2026-05-31 00:00:02',
            'wp_version'      => '6.9.4',
            'php_version'     => '8.2.27',
            'active_theme'    => '{"name":"Twenty Twenty-Four","version":"1.0","parent":null}',
            'plugin_counts'   => '{"installed":12,"active":8}',
            'theme_counts'    => '{"installed":3,"active":1}',
            'ssl_status'      => 'enabled',
            'ssl_expires_at'  => '2027-01-01 00:00:00',
        ]);

        $json = $site->toJson();

        // F5 fields preserved
        self::assertSame(7, $json['id']);
        self::assertSame('https://x.test', $json['url']);
        self::assertSame('active', $json['status']);
        self::assertSame('2026-05-31 00:00:01', $json['last_contact_at']);

        // F8 NEW fields
        self::assertSame('2026-05-31 00:00:02', $json['last_sync_at']);
        self::assertSame('6.9.4', $json['wp_version']);
        self::assertSame('8.2.27', $json['php_version']);
        self::assertSame(
            ['name' => 'Twenty Twenty-Four', 'version' => '1.0', 'parent' => null],
            $json['active_theme'],
        );
        self::assertSame(['installed' => 12, 'active' => 8], $json['plugin_counts']);
        self::assertSame(['installed' => 3, 'active' => 1], $json['theme_counts']);
        self::assertSame('enabled', $json['ssl_status']);
        self::assertSame('2027-01-01 00:00:00', $json['ssl_expires_at']);

        // Sensitive fields STILL hidden
        self::assertArrayNotHasKey('user_id', $json);
        self::assertArrayNotHasKey('our_public_key', $json);
        self::assertArrayNotHasKey('our_private_key', $json);
        self::assertArrayNotHasKey('site_public_key', $json);
    }

    public function testOfflineStatusPassesThrough(): void
    {
        $site = Site::fromRow([
            'id'              => 8,
            'user_id'         => 1,
            'url'             => 'https://y.test',
            'label'           => 'Y',
            'status'          => 'offline',
            'our_public_key'  => 'pub',
            'our_private_key' => 'priv',
            'site_public_key' => 'sitepub',
            'last_contact_at' => null,
            'last_sync_at'    => null,
            'last_error'      => 'host unreachable',
            'created_at'      => '2026-05-31 00:00:00',
            'updated_at'      => '2026-05-31 00:00:00',
            'wp_version'      => null,
            'php_version'     => null,
            'active_theme'    => null,
            'plugin_counts'   => null,
            'theme_counts'    => null,
            'ssl_status'      => null,
            'ssl_expires_at'  => null,
        ]);

        self::assertSame('offline', $site->toJson()['status']);
    }
}
