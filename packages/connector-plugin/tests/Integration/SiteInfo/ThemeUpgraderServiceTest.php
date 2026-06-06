<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\SiteInfo;

use Defyn\Connector\SiteInfo\CapturingUpgraderSkin;
use Defyn\Connector\SiteInfo\NoThemeUpdateAvailableException;
use Defyn\Connector\SiteInfo\ThemeUpgraderService;
use Defyn\Connector\SiteInfo\ThemeUpgradeFailedException;
use Defyn\Connector\SiteInfo\UnknownThemeSlugException;
use WP_UnitTestCase;

final class ThemeUpgraderServiceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_themes');
    }

    public function testUnknownSlugThrows(): void
    {
        $service = new ThemeUpgraderService(fn () => $this->fail('factory should not be called'));

        $this->expectException(UnknownThemeSlugException::class);
        $this->expectExceptionMessage('definitely-not-installed');
        $service->upgrade('definitely-not-installed');
    }

    public function testNoUpdateAvailableThrows(): void
    {
        $stylesheet = (string) get_stylesheet();

        $service = new ThemeUpgraderService(fn () => $this->fail('factory should not be called'));

        $this->expectException(NoThemeUpdateAvailableException::class);
        $this->expectExceptionMessage($stylesheet);
        $service->upgrade($stylesheet);
    }

    public function testUpgradeFailedWhenUpgraderReturnsFalse(): void
    {
        $stylesheet = (string) get_stylesheet();
        $this->seedUpdateAvailable($stylesheet, '99.9');

        $service = new ThemeUpgraderService(function (CapturingUpgraderSkin $skin) {
            $skin->error('Destination folder already exists.');
            return new class { public function upgrade(string $stylesheet) { return false; } };
        });

        $this->expectException(ThemeUpgradeFailedException::class);
        $this->expectExceptionMessage('Destination folder already exists.');
        $service->upgrade($stylesheet);
    }

    public function testUpgradeFailedWhenUpgraderReturnsWpError(): void
    {
        $stylesheet = (string) get_stylesheet();
        $this->seedUpdateAvailable($stylesheet, '99.9');

        $service = new ThemeUpgraderService(fn () => new class {
            public function upgrade(string $stylesheet) {
                return new \WP_Error('download_failed', 'HTTP 404 from update_uri.');
            }
        });

        $this->expectException(ThemeUpgradeFailedException::class);
        $this->expectExceptionMessage('HTTP 404 from update_uri.');
        $service->upgrade($stylesheet);
    }

    public function testUpgradeSucceedsAndReturnsExpectedShape(): void
    {
        $stylesheet = (string) get_stylesheet();
        $previousVersion = (string) wp_get_theme($stylesheet)->get('Version');
        $this->seedUpdateAvailable($stylesheet, '99.9');

        $service = new ThemeUpgraderService(fn () => new class {
            public function upgrade(string $stylesheet) { return true; }
        });

        $before = time();
        $result = $service->upgrade($stylesheet);
        $after = time();

        $this->assertTrue($result['success']);
        $this->assertSame($stylesheet, $result['slug']);
        $this->assertSame($previousVersion, $result['previous_version']);
        $this->assertSame($previousVersion, $result['new_version']);
        $this->assertIsInt($result['server_time']);
        $this->assertGreaterThanOrEqual($before, $result['server_time']);
        $this->assertLessThanOrEqual($after, $result['server_time']);
    }

    private function seedUpdateAvailable(string $stylesheet, string $newVersion): void
    {
        $update = new \stdClass();
        $update->response = [
            $stylesheet => [
                'theme'       => $stylesheet,
                'new_version' => $newVersion,
                'package'     => 'https://example.test/theme.zip',
            ],
        ];
        set_site_transient('update_themes', $update);
    }
}
