<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\SiteInfo;

use Defyn\Connector\SiteInfo\ThemeListCollector;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class ThemeListCollectorTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_themes');
    }

    public function testReturnsEntryForEachInstalledTheme(): void
    {
        $result = (new ThemeListCollector())->collect();

        self::assertArrayHasKey('themes', $result);
        self::assertNotEmpty($result['themes'], 'WP test fixture ships at least one theme');

        foreach ($result['themes'] as $theme) {
            self::assertArrayHasKey('slug', $theme);
            self::assertArrayHasKey('name', $theme);
            self::assertArrayHasKey('version', $theme);
            self::assertArrayHasKey('parent_slug', $theme);
            self::assertArrayHasKey('is_active', $theme);
            self::assertArrayHasKey('update_available', $theme);
            self::assertArrayHasKey('update_version', $theme);
            self::assertIsBool($theme['is_active']);
            self::assertIsBool($theme['update_available']);
        }
    }

    public function testExactlyOneRowIsActive(): void
    {
        $result = (new ThemeListCollector())->collect();
        $activeCount = 0;
        foreach ($result['themes'] as $theme) {
            if ($theme['is_active']) {
                $activeCount++;
            }
        }
        self::assertSame(1, $activeCount);
    }

    public function testActiveSlugMatchesGetStylesheet(): void
    {
        $result = (new ThemeListCollector())->collect();
        $active = null;
        foreach ($result['themes'] as $theme) {
            if ($theme['is_active']) {
                $active = $theme;
                break;
            }
        }
        self::assertNotNull($active);
        self::assertSame((string) get_stylesheet(), $active['slug']);
    }

    public function testUpdateAvailableDerivedFromTransient(): void
    {
        $active = (string) get_stylesheet();
        $fake = new \stdClass();
        $fake->response = [
            $active => ['new_version' => '99.9'],
        ];
        set_site_transient('update_themes', $fake);

        $result = (new ThemeListCollector())->collect();
        $bySlug = [];
        foreach ($result['themes'] as $t) {
            $bySlug[$t['slug']] = $t;
        }
        self::assertTrue($bySlug[$active]['update_available']);
        self::assertSame('99.9', $bySlug[$active]['update_version']);
    }

    public function testUpdateAvailableFalseWhenTransientEmpty(): void
    {
        delete_site_transient('update_themes');
        $result = (new ThemeListCollector())->collect();
        foreach ($result['themes'] as $t) {
            self::assertFalse($t['update_available']);
            self::assertNull($t['update_version']);
        }
    }
}
