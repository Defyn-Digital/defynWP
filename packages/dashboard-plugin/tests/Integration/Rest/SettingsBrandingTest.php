<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\SettingsController;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P5.2 — report_branding in GET /defyn/v1/settings + POST /defyn/v1/settings/report-branding.
 *
 * Tests call handleGet / handleSetBranding DIRECTLY with a pre-populated
 * _authenticated_user_id param, bypassing the RateLimit permission_callback.
 *
 * @group integration
 */
final class SettingsBrandingTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::activate();

        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
    }

    private function postBranding(int $userId, array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/defyn/v1/settings/report-branding');
        $req->set_param('_authenticated_user_id', $userId);
        $req->set_body(json_encode($body));
        $req->set_header('Content-Type', 'application/json');
        return (new SettingsController())->handleSetBranding($req);
    }

    private function getSettings(int $userId): \WP_REST_Response
    {
        $req = new WP_REST_Request('GET', '/defyn/v1/settings');
        $req->set_param('_authenticated_user_id', $userId);
        return (new SettingsController())->handleGet($req);
    }

    // -------------------------------------------------------------------------
    // GET /settings — includes report_branding with defaults (no regression on
    // the existing slack_webhook_url key)
    // -------------------------------------------------------------------------

    public function testGetIncludesReportBrandingDefaults(): void
    {
        $res = $this->getSettings(1);

        self::assertSame(200, $res->get_status());
        $data = $res->get_data();

        // Must not regress the existing P3.3 key.
        self::assertArrayHasKey('slack_webhook_url', $data);

        self::assertArrayHasKey('report_branding', $data);
        self::assertSame('Defyn Digital', $data['report_branding']['agency_name']);
        self::assertSame('#26215C', $data['report_branding']['accent_color']);
        self::assertSame('', $data['report_branding']['logo_url']);
    }

    // -------------------------------------------------------------------------
    // POST valid branding — stored + echoed, and a later GET reflects it
    // -------------------------------------------------------------------------

    public function testSetBrandingValidStores(): void
    {
        global $wpdb;

        $res = $this->postBranding(1, [
            'agency_name'  => 'Acme Co',
            'accent_color' => '#112233',
            'logo_url'     => 'https://cdn.test/l.png',
        ]);

        self::assertSame(200, $res->get_status());
        $branding = $res->get_data()['report_branding'];
        self::assertSame('Acme Co', $branding['agency_name']);
        self::assertSame('#112233', $branding['accent_color']);
        self::assertSame('https://cdn.test/l.png', $branding['logo_url']);

        // A later GET must reflect the stored values.
        $get = $this->getSettings(1)->get_data()['report_branding'];
        self::assertSame('Acme Co', $get['agency_name']);
        self::assertSame('#112233', $get['accent_color']);
        self::assertSame('https://cdn.test/l.png', $get['logo_url']);

        // Activity log row must exist.
        $row = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log
             WHERE event_type = 'settings.report_branding_updated'
             ORDER BY id DESC LIMIT 1",
            ARRAY_A
        );
        self::assertNotNull($row, 'Activity log row must exist');

        // The activity log records ONLY {cleared_logo: bool} — never the values.
        self::assertStringNotContainsString('Acme Co', (string) ($row['details'] ?? ''));
        self::assertStringNotContainsString('cdn.test', (string) ($row['details'] ?? ''));
        $details = json_decode($row['details'] ?? '{}', true);
        self::assertArrayHasKey('cleared_logo', $details);
        self::assertFalse($details['cleared_logo']);
    }

    // -------------------------------------------------------------------------
    // Validation rejects — all return settings.invalid_branding
    // -------------------------------------------------------------------------

    public function testInvalidHexReturns400(): void
    {
        $res = $this->postBranding(1, ['accent_color' => 'red']);
        self::assertSame(400, $res->get_status());
        self::assertSame('settings.invalid_branding', $res->get_data()['error']['code']);
    }

    public function testNonHttpsLogoReturns400(): void
    {
        $res = $this->postBranding(1, ['logo_url' => 'http://x']);
        self::assertSame(400, $res->get_status());
        self::assertSame('settings.invalid_branding', $res->get_data()['error']['code']);
    }

    public function testOverLongAgencyReturns400(): void
    {
        $res = $this->postBranding(1, ['agency_name' => str_repeat('a', 101)]);
        self::assertSame(400, $res->get_status());
        self::assertSame('settings.invalid_branding', $res->get_data()['error']['code']);
    }
}
