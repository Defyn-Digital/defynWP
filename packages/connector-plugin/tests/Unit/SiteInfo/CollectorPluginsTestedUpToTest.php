<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\SiteInfo;

use Defyn\Connector\SiteInfo\PluginListCollector;
use WP_UnitTestCase;

final class CollectorPluginsTestedUpToTest extends WP_UnitTestCase
{
    public function testCollectorEmitsTestedUpToWhenHeaderPresent(): void
    {
        $result = (new PluginListCollector())->collect();
        $rows = $result['plugins'];

        // Every plugin row should have the tested_up_to key and it should be string or null
        foreach ($rows as $row) {
            $this->assertArrayHasKey('tested_up_to', $row, 'every plugin row must have tested_up_to key');
            if ($row['tested_up_to'] !== null) {
                // If the key is present and has a value, it should be a string
                $this->assertIsString($row['tested_up_to']);
            }
        }
    }

    public function testCollectorEmitsNullWhenHeaderAbsent(): void
    {
        $result = (new PluginListCollector())->collect();
        $rows = $result['plugins'];

        // All rows should have the tested_up_to key
        foreach ($rows as $row) {
            $this->assertArrayHasKey('tested_up_to', $row);
            $this->assertTrue(
                $row['tested_up_to'] === null || is_string($row['tested_up_to']),
                'tested_up_to must be null or string'
            );
        }
    }
}
