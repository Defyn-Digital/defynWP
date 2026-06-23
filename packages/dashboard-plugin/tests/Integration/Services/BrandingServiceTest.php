<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\BrandingService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class BrandingServiceTest extends AbstractSchemaTestCase
{
    /** Clean shared options before each test so they don't bleed across runs. */
    protected function setUp(): void
    {
        parent::setUp();
        delete_option('defyn_report_agency_name');
        delete_option('defyn_report_accent_color');
        delete_option('defyn_report_logo_url');
        delete_option('defyn_branding_migrated');
    }

    public function testGetReturnsDefaultsWhenUnset(): void
    {
        $uid = self::factory()->user->create();
        $b = (new BrandingService())->get($uid);
        // No hardcoded agency default — empty until the operator sets one.
        self::assertSame('', $b['agency_name']);
        self::assertSame('#26215C', $b['accent_color']);
        self::assertSame('', $b['logo_url']);
    }

    public function testSetThenGetRoundTrips(): void
    {
        $uid = self::factory()->user->create();
        $svc = new BrandingService();
        $svc->set($uid, ['agency_name'=>'Acme Co','accent_color'=>'#112233','logo_url'=>'https://cdn.test/l.png']);
        $b = $svc->get($uid);
        self::assertSame('Acme Co', $b['agency_name']);
        self::assertSame('#112233', $b['accent_color']);
        self::assertSame('https://cdn.test/l.png', $b['logo_url']);
    }

    public function testEmptyValueResetsToDefault(): void
    {
        $uid = self::factory()->user->create();
        $svc = new BrandingService();
        $svc->set($uid, ['agency_name'=>'Acme Co']);
        $svc->set($uid, ['agency_name'=>'']);
        // Clearing the agency leaves it empty (no hardcoded fallback).
        self::assertSame('', $svc->get($uid)['agency_name']);
    }

    public function testBrandingIsSharedAcrossUsers(): void
    {
        $svc = new BrandingService();
        $svc->set(11, ['agency_name' => 'Defyn']);
        // a DIFFERENT user reads the same shared brand
        $this->assertSame('Defyn', $svc->get(22)['agency_name']);
    }

    public function testMigratesLegacyOwnerMetaIntoSharedOption(): void
    {
        update_user_meta(1, 'defyn_report_agency_name', 'Legacy Brand');
        delete_option('defyn_branding_migrated');
        delete_option('defyn_report_agency_name');
        BrandingService::migrateLegacyToShared();
        $this->assertSame('Legacy Brand', (new BrandingService())->get(99)['agency_name']);
        $this->assertSame('1', (string) get_option('defyn_branding_migrated'));
    }
}
