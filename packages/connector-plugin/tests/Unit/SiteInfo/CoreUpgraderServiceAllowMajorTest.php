<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\SiteInfo;

use Defyn\Connector\SiteInfo\CapturingUpgraderSkin;
use Defyn\Connector\SiteInfo\CoreUpgraderService;
use Defyn\Connector\SiteInfo\MajorUpdateBlockedException;
use WP_UnitTestCase;

final class CoreUpgraderServiceAllowMajorTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_core');
    }

    public function testUpgradeDefaultsToBlockedForMajorWithoutAllowFlag(): void
    {
        // Get current version and create a major version bump target.
        $current = (string) get_bloginfo('version');
        [$maj] = explode('.', $current) + [0 => '0'];
        $target = ((int) $maj + 1) . '.0';
        $this->seedUpdateAvailable($target);

        $service = new CoreUpgraderService(fn () => $this->fail('factory should not be called'));

        $this->expectException(MajorUpdateBlockedException::class);
        $service->upgrade(); // no argument -- backward compat: defaults to allowMajor=false
    }

    public function testUpgradeAcceptsAllowMajorParamAndProceedsOnMajor(): void
    {
        // Get current version and create a major version bump target.
        $current = (string) get_bloginfo('version');
        [$maj] = explode('.', $current) + [0 => '0'];
        $target = ((int) $maj + 1) . '.0';
        $this->seedUpdateAvailable($target);

        // Inject a no-op upgrader factory that returns true (simulating successful upgrade).
        $factory = static fn(CapturingUpgraderSkin $skin): object => new class {
            public function upgrade(\stdClass $update): bool
            {
                return true;
            }
        };
        $service = new CoreUpgraderService($factory);

        // Should NOT throw -- allowMajor=true permits the bump.
        $result = $service->upgrade(true);

        $this->assertTrue($result['success']);
        $this->assertSame($current, $result['previous_version']);
    }

    private function seedUpdateAvailable(string $target): void
    {
        $update = new \stdClass();
        $update->updates = [(object) [
            'response' => 'upgrade',
            'current'  => $target,
            'version'  => $target,
            'package'  => 'https://example.test/wp.zip',
            'locale'   => 'en_US',
        ]];
        $update->version_checked = (string) get_bloginfo('version');
        set_site_transient('update_core', $update);
    }
}
