<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\SiteInfo;

use Defyn\Connector\SiteInfo\Collector;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class CollectorCoreTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_core');
    }

    public function testCoreSubObjectPresentWhenNoUpdateAvailable(): void
    {
        $result = (new Collector())->collect();

        self::assertArrayHasKey('core', $result);
        $core = $result['core'];
        self::assertFalse($core['update_available']);
        self::assertNull($core['update_version']);
        self::assertFalse($core['is_minor_update']);
        self::assertIsBool($core['is_auto_update_enabled']);
    }

    public function testCoreSurfacesMinorUpdateFromTransient(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $target = $maj . '.' . $min . '.1';

        $update = new \stdClass();
        $update->updates = [(object) [
            'response' => 'upgrade',
            'current'  => $target,
            'version'  => $target,
            'locale'   => 'en_US',
        ]];
        set_site_transient('update_core', $update);

        $result = (new Collector())->collect();
        $core = $result['core'];

        self::assertTrue($core['update_available']);
        self::assertSame($target, $core['update_version']);
        self::assertTrue($core['is_minor_update']);
    }

    public function testCoreFlagsMajorUpdateAsNonMinor(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj] = explode('.', $current) + [0 => '0'];
        $target = ((int) $maj + 1) . '.0';

        $update = new \stdClass();
        $update->updates = [(object) [
            'response' => 'upgrade',
            'current'  => $target,
            'version'  => $target,
            'locale'   => 'en_US',
        ]];
        set_site_transient('update_core', $update);

        $result = (new Collector())->collect();
        $core = $result['core'];

        self::assertTrue($core['update_available']);
        self::assertSame($target, $core['update_version']);
        self::assertFalse($core['is_minor_update']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAutoUpdatesEnabledWhenConstantUndefined(): void
    {
        $result = (new Collector())->collect();
        self::assertTrue($result['core']['is_auto_update_enabled']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAutoUpdatesEnabledWhenConstantTrue(): void
    {
        define('WP_AUTO_UPDATE_CORE', true);
        $result = (new Collector())->collect();
        self::assertTrue($result['core']['is_auto_update_enabled']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAutoUpdatesEnabledWhenConstantMinor(): void
    {
        define('WP_AUTO_UPDATE_CORE', 'minor');
        $result = (new Collector())->collect();
        self::assertTrue($result['core']['is_auto_update_enabled']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAutoUpdatesDisabledWhenConstantFalse(): void
    {
        define('WP_AUTO_UPDATE_CORE', false);
        $result = (new Collector())->collect();
        self::assertFalse($result['core']['is_auto_update_enabled']);
    }
}
