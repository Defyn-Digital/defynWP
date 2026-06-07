<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\SiteInfo;

use Defyn\Connector\SiteInfo\Collector;
use WP_UnitTestCase;

final class CollectorIsMajorUpdateTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_core');
    }

    public function testIsMajorUpdateAvailableTrueWhenMajorBumpPending(): void
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

        $this->assertArrayHasKey('core', $result);
        $this->assertTrue($result['core']['update_available']);
        $this->assertFalse($result['core']['is_minor_update']);
        $this->assertTrue($result['core']['is_major_update_available']);
    }

    public function testIsMajorUpdateAvailableFalseWhenMinorOnly(): void
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

        $this->assertTrue($result['core']['is_minor_update']);
        $this->assertFalse($result['core']['is_major_update_available']);
    }

    public function testIsMajorUpdateAvailableFalseWhenNoUpdate(): void
    {
        $result = (new Collector())->collect();

        $this->assertFalse($result['core']['update_available']);
        $this->assertFalse($result['core']['is_minor_update']);
        $this->assertFalse($result['core']['is_major_update_available']);
    }
}
