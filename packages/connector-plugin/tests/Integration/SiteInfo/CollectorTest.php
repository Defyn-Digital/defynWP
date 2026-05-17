<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\SiteInfo;

use Defyn\Connector\SiteInfo\Collector;
use WP_UnitTestCase;

final class CollectorTest extends WP_UnitTestCase
{
    public function testReturnsExpectedKeys(): void
    {
        $info = (new Collector())->collect();

        $this->assertArrayHasKey('wp_version', $info);
        $this->assertArrayHasKey('php_version', $info);
        $this->assertArrayHasKey('active_theme', $info);
        $this->assertArrayHasKey('plugin_counts', $info);
        $this->assertArrayHasKey('theme_counts', $info);
        $this->assertArrayHasKey('ssl_status', $info);
        $this->assertArrayHasKey('ssl_expires_at', $info);
        $this->assertArrayHasKey('server_time', $info);
    }

    public function testWpVersionMatchesBloginfo(): void
    {
        $info = (new Collector())->collect();
        $this->assertSame(get_bloginfo('version'), $info['wp_version']);
    }

    public function testPhpVersionMatchesPhpversion(): void
    {
        $info = (new Collector())->collect();
        $this->assertSame(phpversion(), $info['php_version']);
    }

    public function testActiveThemeHasNameAndVersion(): void
    {
        $info = (new Collector())->collect();
        $this->assertIsArray($info['active_theme']);
        $this->assertArrayHasKey('name', $info['active_theme']);
        $this->assertArrayHasKey('version', $info['active_theme']);
        $this->assertArrayHasKey('parent', $info['active_theme']);
    }

    public function testPluginCountsShape(): void
    {
        $info = (new Collector())->collect();
        $this->assertArrayHasKey('installed', $info['plugin_counts']);
        $this->assertArrayHasKey('active', $info['plugin_counts']);
        $this->assertIsInt($info['plugin_counts']['installed']);
        $this->assertIsInt($info['plugin_counts']['active']);
        $this->assertGreaterThanOrEqual($info['plugin_counts']['active'], $info['plugin_counts']['installed']);
    }

    public function testServerTimeIsUnixTimestamp(): void
    {
        $before = time();
        $info   = (new Collector())->collect();
        $after  = time();

        $this->assertIsInt($info['server_time']);
        $this->assertGreaterThanOrEqual($before, $info['server_time']);
        $this->assertLessThanOrEqual($after, $info['server_time']);
    }
}
