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
        // No update_plugins transient → no update available. The no-op refresher
        // keeps wp_update_plugins() from making a real network call mid-test.
        $service = new PluginUpgraderService(
            fn () => $this->fail('upgrader factory should not be called'),
            null,
            $this->noopRefresher()
        );

        $this->expectException(NoUpdateAvailableException::class);
        $this->expectExceptionMessage('hello.php');
        $service->upgrade('hello.php');
    }

    public function testUpgradeFailedWhenUpgraderReturnsFalse(): void
    {
        $this->seedUpdateAvailable('hello.php', '1.7.3');

        $service = new PluginUpgraderService(
            function (CapturingUpgraderSkin $skin) {
                $skin->error('Could not copy file.');
                return new class { public function upgrade(string $pluginFile) { return false; } };
            },
            null,
            $this->noopRefresher()
        );

        $this->expectException(UpgradeFailedException::class);
        $this->expectExceptionMessage('Could not copy file.');
        $service->upgrade('hello.php');
    }

    public function testUpgradeFailedWhenUpgraderReturnsWpError(): void
    {
        $this->seedUpdateAvailable('hello.php', '1.7.3');

        $service = new PluginUpgraderService(
            fn () => new class {
                public function upgrade(string $pluginFile) {
                    return new \WP_Error('download_failed', 'HTTP 404 from update_uri.');
                }
            },
            null,
            $this->noopRefresher()
        );

        $this->expectException(UpgradeFailedException::class);
        $this->expectExceptionMessage('HTTP 404 from update_uri.');
        $service->upgrade('hello.php');
    }

    public function testUpgradeSucceedsAndReturnsExpectedShape(): void
    {
        $this->seedUpdateAvailable('hello.php', '1.7.3');

        // Stub returns true. In production the upgrader swaps files and the
        // version reader picks up the new version off disk; here we inject a
        // reader that returns a BUMPED version (1.7.2 → 1.7.3) so the happy path
        // exercises the new "version advanced" success branch.
        $service = new PluginUpgraderService(
            fn () => new class {
                public function upgrade(string $pluginFile) { return true; }
            },
            fn (string $slug, string $pluginFile, string $previousVersion): string => '1.7.3',
            $this->noopRefresher()
        );

        $before = time();
        $result = $service->upgrade('hello.php');
        $after = time();

        $this->assertTrue($result['success']);
        $this->assertSame('hello.php', $result['slug']);
        $this->assertSame('1.7.2', $result['previous_version']); // hello.php ships at 1.7.2 in wp-phpunit fixtures
        $this->assertSame('1.7.3', $result['new_version']); // injected reader simulates the on-disk bump
        $this->assertIsInt($result['server_time']);
        $this->assertGreaterThanOrEqual($before, $result['server_time']);
        $this->assertLessThanOrEqual($after, $result['server_time']);
    }

    /**
     * Regression for the v0.2.3 cache-refresh fix. The default version reader
     * calls wp_clean_plugins_cache(true), clearstatcache(), and
     * opcache_invalidate() before re-reading the version with get_plugin_data().
     * Here we let the DEFAULT reader run for real (no injected reader) against
     * hello.php — but inject a fresher previous version onto disk is impossible,
     * so we assert the real reader resolves hello.php's shipped 1.7.2 header.
     * Because that equals previous (the stub never swaps files), the no-op guard
     * fires and we get an UpgradeFailedException — proving the default reader is
     * wired and the guard catches a genuine on-disk no-op.
     */
    public function testUpgradeWithDefaultReaderDetectsNoOpAndThrows(): void
    {
        $this->seedUpdateAvailable('hello.php', '1.7.3');

        $service = new PluginUpgraderService(
            fn () => new class {
                public function upgrade(string $pluginFile) { return true; }
            },
            null, // exercise the REAL default version reader (cache-refresh path)
            $this->noopRefresher()
        );

        $this->expectException(UpgradeFailedException::class);
        $this->expectExceptionMessage('version did not change');
        $service->upgrade('hello.php');
    }

    /**
     * The false-success bug from cuscal.com: the upgrader returns true but the
     * version on disk never advanced. With a stub reader returning the SAME
     * version as before, the no-op guard must throw with diagnostics rather than
     * report a false success the dashboard would record as "updated".
     */
    public function testUpgradeThrowsWhenVersionDidNotChange(): void
    {
        $this->seedUpdateAvailable('hello.php', '1.7.3');

        $service = new PluginUpgraderService(
            fn () => new class {
                public function upgrade(string $pluginFile) { return true; }
            },
            // Reader returns the previous version unchanged → simulates a silent
            // no-op filesystem that wrote nothing.
            fn (string $slug, string $pluginFile, string $previousVersion): string => $previousVersion,
            $this->noopRefresher()
        );

        try {
            $service->upgrade('hello.php');
            $this->fail('Expected UpgradeFailedException for unchanged version');
        } catch (UpgradeFailedException $e) {
            $this->assertStringContainsString('version did not change', $e->getMessage());
            $this->assertStringContainsString('upgrader_errors=none', $e->getMessage());
        }
    }

    /**
     * A no-op transient refresher so tests keep their manually-seeded
     * update_plugins transient (the real wp_update_plugins() would clobber it
     * and/or hit api.wordpress.org).
     *
     * @return callable(): void
     */
    private function noopRefresher(): callable
    {
        return static function (): void {};
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
