<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\SiteInfo;

use Defyn\Connector\SiteInfo\PluginListCollector;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class PluginListCollectorTest extends WP_UnitTestCase
{
    public function testReturnsEmptyListWhenNoPluginsInstalled(): void
    {
        $result = (new PluginListCollector())->collect();

        self::assertArrayHasKey('plugins', $result);
        self::assertArrayHasKey('truncated', $result);
        self::assertIsArray($result['plugins']);
        self::assertIsBool($result['truncated']);
        self::assertFalse($result['truncated'], 'test fixture has < 500 plugins');
    }

    public function testRowsAreSortedBySlugAscending(): void
    {
        $result = (new PluginListCollector())->collect();
        $slugs  = array_column($result['plugins'], 'slug');
        $sorted = $slugs;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $slugs, 'plugins must be sorted by slug ascending');
    }

    public function testDerivesUpdateAvailableFromUpdatePluginsTransient(): void
    {
        $fakeUpdates           = new \stdClass();
        $fakeUpdates->response = [
            'hello.php' => (object) ['new_version' => '99.9.9'],
        ];
        set_site_transient('update_plugins', $fakeUpdates);

        $result  = (new PluginListCollector())->collect();
        $byPath  = array_column($result['plugins'], null, 'slug');

        self::assertArrayHasKey('hello.php', $byPath, 'Hello Dolly should be installed in WP test fixture');
        self::assertTrue($byPath['hello.php']['update_available']);
        self::assertSame('99.9.9', $byPath['hello.php']['update_version']);

        delete_site_transient('update_plugins');
    }

    public function testUpdateAvailableFalseWhenNoTransientEntry(): void
    {
        delete_site_transient('update_plugins');

        $result = (new PluginListCollector())->collect();
        foreach ($result['plugins'] as $p) {
            self::assertFalse($p['update_available'], "plugin {$p['slug']} should not have an update");
            self::assertNull($p['update_version'], "plugin {$p['slug']} update_version should be null");
        }
    }
}
