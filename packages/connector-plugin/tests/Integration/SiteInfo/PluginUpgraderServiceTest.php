<?php
declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\SiteInfo;

use Defyn\Connector\SiteInfo\CapturingUpgraderSkin;
use Defyn\Connector\SiteInfo\NoUpdateAvailableException;
use Defyn\Connector\SiteInfo\PluginUpgraderService;
use Defyn\Connector\SiteInfo\UnknownSlugException;
use Defyn\Connector\SiteInfo\UpgradeFailedException;
use WP_UnitTestCase;

final class PluginUpgraderServiceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_plugins');
    }

    public function testUnknownSlugThrows(): void
    {
        $service = new PluginUpgraderService(fn () => $this->fail('upgrader factory should not be called for unknown slug'));

        $this->expectException(UnknownSlugException::class);
        $this->expectExceptionMessage('definitely-not-installed');
        $service->upgrade('definitely-not-installed');
    }

    public function testNoUpdateAvailableThrows(): void
    {
        // hello.php is shipped with WP for tests as a single-file plugin (no folder),
        // so its slug under our strtok-based folder resolution is "hello.php".
        // No update_plugins transient → no update available
        $service = new PluginUpgraderService(fn () => $this->fail('upgrader factory should not be called'));

        $this->expectException(NoUpdateAvailableException::class);
        $this->expectExceptionMessage('hello.php');
        $service->upgrade('hello.php');
    }

    public function testUpgradeFailedWhenUpgraderReturnsFalse(): void
    {
        $this->seedUpdateAvailable('hello.php', '1.7.3');

        $service = new PluginUpgraderService(function (CapturingUpgraderSkin $skin) {
            $skin->error('Could not copy file.');
            return new class { public function upgrade(string $pluginFile) { return false; } };
        });

        $this->expectException(UpgradeFailedException::class);
        $this->expectExceptionMessage('Could not copy file.');
        $service->upgrade('hello.php');
    }

    public function testUpgradeFailedWhenUpgraderReturnsWpError(): void
    {
        $this->seedUpdateAvailable('hello.php', '1.7.3');

        $service = new PluginUpgraderService(fn () => new class {
            public function upgrade(string $pluginFile) {
                return new \WP_Error('download_failed', 'HTTP 404 from update_uri.');
            }
        });

        $this->expectException(UpgradeFailedException::class);
        $this->expectExceptionMessage('HTTP 404 from update_uri.');
        $service->upgrade('hello.php');
    }

    public function testUpgradeSucceedsAndReturnsExpectedShape(): void
    {
        $this->seedUpdateAvailable('hello.php', '1.7.3');

        // Stub returns true; reading the new version after the call would normally
        // require WP to have actually swapped files. For the test we just verify
        // shape + that previous_version came from get_plugins() BEFORE the call.
        $service = new PluginUpgraderService(fn () => new class {
            public function upgrade(string $pluginFile) { return true; }
        });

        $before = time();
        $result = $service->upgrade('hello.php');
        $after = time();

        $this->assertTrue($result['success']);
        $this->assertSame('hello.php', $result['slug']);
        $this->assertSame('1.7.2', $result['previous_version']); // hello.php ships at 1.7.2 in wp-phpunit fixtures
        $this->assertSame('1.7.2', $result['new_version']); // stub didn't change files, so re-read returns the same
        $this->assertIsInt($result['server_time']);
        $this->assertGreaterThanOrEqual($before, $result['server_time']);
        $this->assertLessThanOrEqual($after, $result['server_time']);
    }

    /**
     * Regression for the v0.2.3 cache-refresh fix. After a successful upgrade,
     * upgrade() now calls wp_clean_plugins_cache(true), clearstatcache(), and
     * opcache_invalidate() before re-reading the version with get_plugin_data().
     * Those calls run for real here (no stub), so this guards that the shape
     * survives the refresh and that re-reading after wp_clean_plugins_cache()
     * still resolves the version from disk.
     */
    public function testUpgradeWithCacheRefreshStillReturnsExpectedShape(): void
    {
        $this->seedUpdateAvailable('hello.php', '1.7.3');

        $service = new PluginUpgraderService(fn () => new class {
            public function upgrade(string $pluginFile) { return true; }
        });

        $result = $service->upgrade('hello.php');

        $this->assertTrue($result['success']);
        $this->assertSame('hello.php', $result['slug']);
        $this->assertArrayHasKey('previous_version', $result);
        $this->assertArrayHasKey('new_version', $result);
        // Stub didn't swap files; after the cache flush the re-read still finds
        // hello.php's shipped header (1.7.2), never an empty string.
        $this->assertSame('1.7.2', $result['previous_version']);
        $this->assertSame('1.7.2', $result['new_version']);
        $this->assertNotSame('', $result['new_version']);
    }

    /**
     * Stand up the update_plugins transient shape WP expects so
     * isset($updates->response[$pluginFile]) is true.
     *
     * In the wp-phpunit fixture, hello.php is a single-file plugin so its
     * plugin-file key is just "hello.php" (no folder/main-file pair).
     */
    private function seedUpdateAvailable(string $pluginFile, string $newVersion): void
    {
        $update = new \stdClass();
        $update->response = [
            $pluginFile => (object) [
                'slug'        => $pluginFile,
                'new_version' => $newVersion,
                'package'     => 'https://example.test/plugin.zip',
            ],
        ];
        set_site_transient('update_plugins', $update);
    }
}
