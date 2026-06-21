<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\BrandingService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class BrandingServiceTest extends AbstractSchemaTestCase
{
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
}
