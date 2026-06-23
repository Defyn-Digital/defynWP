<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\SiteInfo;

use Defyn\Connector\SiteInfo\CapturingUpgraderSkin;
use Defyn\Connector\SiteInfo\CoreUpgradeFailedException;
use Defyn\Connector\SiteInfo\CoreUpgraderService;
use Defyn\Connector\SiteInfo\MajorUpdateBlockedException;
use Defyn\Connector\SiteInfo\NoCoreUpdateAvailableException;
use WP_UnitTestCase;

final class CoreUpgraderServiceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_core');
        add_filter('pre_set_site_transient_update_core', static fn ($value) => $value, 10, 1);
    }

    public function testUpgradeWithNoUpdateThrowsNoCoreUpdateAvailable(): void
    {
        $service = new CoreUpgraderService(
            fn () => $this->fail('factory should not be called'),
            $this->noopRefresher()
        );

        $this->expectException(NoCoreUpdateAvailableException::class);
        $service->upgrade();
    }

    public function testUpgradeWithMajorBumpThrowsMajorUpdateBlocked(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj] = explode('.', $current) + [0 => '0'];
        $target = ((int) $maj + 1) . '.0';
        $this->seedUpdateAvailable($target);

        $service = new CoreUpgraderService(
            fn () => $this->fail('factory should not be called'),
            $this->noopRefresher()
        );

        $this->expectException(MajorUpdateBlockedException::class);
        $this->expectExceptionMessage($target);
        $service->upgrade();
    }

    public function testUpgradeWithFalseReturnThrowsCoreUpgradeFailed(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $target = $maj . '.' . $min . '.1';
        $this->seedUpdateAvailable($target);

        $service = new CoreUpgraderService(
            function (CapturingUpgraderSkin $skin) {
                $skin->error('Could not copy file. /wp-admin/index.php');
                return new class {
                    public function upgrade($update) { return false; }
                };
            },
            $this->noopRefresher()
        );

        $this->expectException(CoreUpgradeFailedException::class);
        $this->expectExceptionMessage('Could not copy file');
        $service->upgrade();
    }

    public function testUpgradeWithWpErrorThrowsCoreUpgradeFailed(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $target = $maj . '.' . $min . '.1';
        $this->seedUpdateAvailable($target);

        $service = new CoreUpgraderService(
            fn () => new class {
                public function upgrade($update) {
                    return new \WP_Error('download_failed', 'HTTP 404 from downloads.wordpress.org.');
                }
            },
            $this->noopRefresher()
        );

        $this->expectException(CoreUpgradeFailedException::class);
        $this->expectExceptionMessage('HTTP 404');
        $service->upgrade();
    }

    public function testUpgradeSuccessReturnsExpectedShape(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $target = $maj . '.' . $min . '.1';
        $this->seedUpdateAvailable($target);

        // Core deliberately has NO version-advanced guard: $wp_version stays at
        // the OLD value for the rest of this request even after a successful
        // upgrade, so new_version === current here is correct, not a no-op.
        $service = new CoreUpgraderService(
            fn () => new class {
                public function upgrade($update) { return true; }
            },
            $this->noopRefresher()
        );

        $before = time();
        $result = $service->upgrade();
        $after  = time();

        $this->assertTrue($result['success']);
        $this->assertSame($current, $result['previous_version']);
        $this->assertSame($current, $result['new_version']);
        $this->assertIsInt($result['server_time']);
        $this->assertGreaterThanOrEqual($before, $result['server_time']);
        $this->assertLessThanOrEqual($after, $result['server_time']);
    }

    /**
     * @return callable(): void
     */
    private function noopRefresher(): callable
    {
        return static function (): void {};
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
