<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\Theme;
use PHPUnit\Framework\TestCase;

final class ThemeTestedUpToTest extends TestCase
{
    public function testFromRowDefaultsTestedUpToToNull(): void
    {
        $theme = Theme::fromRow($this->baseRow());
        $this->assertNull($theme->testedUpTo);
    }

    public function testFromRowHydratesTestedUpToFromString(): void
    {
        $row = $this->baseRow();
        $row['tested_up_to'] = '6.4';
        $theme = Theme::fromRow($row);
        $this->assertSame('6.4', $theme->testedUpTo);
    }

    public function testToJsonExposesTestedUpToBothBranches(): void
    {
        $row = $this->baseRow();
        $row['tested_up_to'] = '6.4';
        $json = Theme::fromRow($row)->toJson();
        $this->assertArrayHasKey('tested_up_to', $json);
        $this->assertSame('6.4', $json['tested_up_to']);

        $jsonNull = Theme::fromRow($this->baseRow())->toJson();
        $this->assertArrayHasKey('tested_up_to', $jsonNull);
        $this->assertNull($jsonNull['tested_up_to']);
    }

    /** @return array<string, mixed> */
    private function baseRow(): array
    {
        return [
            'id'                     => 1,
            'site_id'                => 1,
            'slug'                   => 'twentytwentyfour',
            'name'                   => 'Twenty Twenty-Four',
            'version'                => '1.0',
            'parent_slug'            => null,
            'is_active'              => '1',
            'update_available'       => '0',
            'update_version'         => null,
            'update_state'           => 'idle',
            'last_update_error'      => null,
            'last_update_attempt_at' => null,
            'last_seen_at'           => '2026-06-07 00:00:00',
            'created_at'             => '2026-06-07 00:00:00',
            'updated_at'             => '2026-06-07 00:00:00',
        ];
    }
}
