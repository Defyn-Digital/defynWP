<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\SiteInfo;

use Defyn\Connector\SiteInfo\PluginListCollector;
use WP_UnitTestCase;

// Note: testing the "header present" branch reliably requires a real plugin
// file that contains a "Tested up to:" header. The WP test suite's bundled
// plugins do not carry that header, so a positive-value assertion cannot be
// made here without spinning up a temporary plugin file. Production smoke at
// smartcoding.com.au is the integration verification for the non-null path.
final class CollectorPluginsTestedUpToTest extends WP_UnitTestCase
{
    public function testEveryPluginRowHasTestedUpToKey(): void
    {
        $result = (new PluginListCollector())->collect();
        $rows   = $result['plugins'];

        foreach ($rows as $row) {
            $this->assertArrayHasKey('tested_up_to', $row, 'every plugin row must have tested_up_to key');
            $this->assertTrue(
                $row['tested_up_to'] === null || is_string($row['tested_up_to']),
                'tested_up_to must be null or string'
            );
        }
    }
}
