<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTestedUpToTest extends TestCase
{
    public function testFromRowDefaultsTestedUpToToNull(): void
    {
        $plugin = Plugin::fromRow($this->baseRow());
        $this->assertNull($plugin->testedUpTo);
    }

    public function testFromRowHydratesTestedUpToFromString(): void
    {
        $row = $this->baseRow();
        $row['tested_up_to'] = '6.4';
        $plugin = Plugin::fromRow($row);
        $this->assertSame('6.4', $plugin->testedUpTo);
    }

    public function testToJsonExposesTestedUpToBothBranches(): void
    {
        $row = $this->baseRow();
        $row['tested_up_to'] = '6.4';
        $json = Plugin::fromRow($row)->toJson();
        $this->assertArrayHasKey('tested_up_to', $json);
        $this->assertSame('6.4', $json['tested_up_to']);

        $jsonNull = Plugin::fromRow($this->baseRow())->toJson();
        $this->assertArrayHasKey('tested_up_to', $jsonNull);
        $this->assertNull($jsonNull['tested_up_to']);
    }

    /** @return array<string, mixed> */
    private function baseRow(): array
    {
        return [
            'id'                     => 1,
            'site_id'                => 1,
            'slug'                   => 'akismet',
            'name'                   => 'Akismet',
            'version'                => '5.0',
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
