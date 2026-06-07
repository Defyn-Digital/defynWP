<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\SiteInfo;

use Defyn\Connector\SiteInfo\ThemeListCollector;
use WP_UnitTestCase;

final class CollectorThemesTestedUpToTest extends WP_UnitTestCase
{
    public function testEveryThemeRowHasTestedUpToKey(): void
    {
        $rows = (new ThemeListCollector())->collect();
        $themes = $rows['themes'];

        $this->assertNotEmpty($themes);
        foreach ($themes as $row) {
            $this->assertArrayHasKey('tested_up_to', $row, 'every theme row must have tested_up_to key');
            $this->assertTrue(
                $row['tested_up_to'] === null || is_string($row['tested_up_to']),
                'tested_up_to must be null or string'
            );
        }
    }
}
