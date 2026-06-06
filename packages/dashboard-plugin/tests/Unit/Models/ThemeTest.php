<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\Theme;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    public function testFromRowMapsAllColumns(): void
    {
        $row = [
            'id'                     => '42',
            'site_id'                => '7',
            'slug'                   => 'twentytwentyfive',
            'name'                   => 'Twenty Twenty-Five',
            'version'                => '1.2',
            'parent_slug'            => null,
            'is_active'              => '1',
            'update_available'       => '1',
            'update_version'         => '1.3',
            'update_state'           => 'idle',
            'last_update_error'      => null,
            'last_update_attempt_at' => null,
            'last_seen_at'           => '2026-06-06 05:00:00',
            'created_at'             => '2026-06-05 09:00:00',
            'updated_at'             => '2026-06-06 05:00:00',
        ];

        $theme = Theme::fromRow($row);

        $this->assertSame(42, $theme->id);
        $this->assertSame(7, $theme->siteId);
        $this->assertSame('twentytwentyfive', $theme->slug);
        $this->assertSame('Twenty Twenty-Five', $theme->name);
        $this->assertSame('1.2', $theme->version);
        $this->assertNull($theme->parentSlug);
        $this->assertTrue($theme->isActive);
        $this->assertTrue($theme->updateAvailable);
        $this->assertSame('1.3', $theme->updateVersion);
        $this->assertSame('idle', $theme->updateState);
    }

    public function testFromRowHandlesChildTheme(): void
    {
        $row = [
            'id' => '5', 'site_id' => '7', 'slug' => 'astra-child', 'name' => 'Astra Child',
            'version' => '1.0.0', 'parent_slug' => 'astra', 'is_active' => '0',
            'update_available' => '0', 'update_version' => null, 'update_state' => 'idle',
            'last_update_error' => null, 'last_update_attempt_at' => null,
            'last_seen_at' => '2026-06-06 05:00:00',
            'created_at' => '2026-06-05 09:00:00', 'updated_at' => '2026-06-06 05:00:00',
        ];

        $theme = Theme::fromRow($row);

        $this->assertSame('astra', $theme->parentSlug);
        $this->assertFalse($theme->isActive);
        $this->assertFalse($theme->updateAvailable);
        $this->assertNull($theme->updateVersion);
    }

    public function testToJsonRendersSnakeCaseKeys(): void
    {
        $row = [
            'id' => '1', 'site_id' => '1', 'slug' => 'astra-child', 'name' => 'Astra Child',
            'version' => '1.0.0', 'parent_slug' => 'astra', 'is_active' => '0',
            'update_available' => '0', 'update_version' => null, 'update_state' => 'idle',
            'last_update_error' => null, 'last_update_attempt_at' => null,
            'last_seen_at' => '2026-06-06 05:00:00',
            'created_at' => '2026-06-05 09:00:00', 'updated_at' => '2026-06-06 05:00:00',
        ];
        $json = Theme::fromRow($row)->toJson();

        $this->assertSame([
            'id', 'site_id', 'slug', 'name', 'version', 'parent_slug', 'is_active',
            'update_available', 'update_version', 'update_state', 'last_update_error',
            'last_update_attempt_at', 'last_seen_at',
        ], array_keys($json));
        $this->assertSame('astra', $json['parent_slug']);
        $this->assertFalse($json['is_active']);
    }
}
