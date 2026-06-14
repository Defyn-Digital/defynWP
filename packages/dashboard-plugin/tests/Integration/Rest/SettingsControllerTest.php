<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\SettingsController;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P3.3 — GET /defyn/v1/settings + POST /defyn/v1/settings/slack-webhook.
 *
 * Tests call handleGet / handleSet DIRECTLY with a pre-populated
 * _authenticated_user_id param, bypassing the RateLimit permission_callback.
 *
 * @group integration
 */
final class SettingsControllerTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::activate();

        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
    }

    // -------------------------------------------------------------------------
    // GET /settings — returns null when no webhook is set
    // -------------------------------------------------------------------------

    public function testGetReturnsNullWhenNoWebhookSet(): void
    {
        $req = new WP_REST_Request('GET', '/defyn/v1/settings');
        $req->set_param('_authenticated_user_id', 1);

        $res = (new SettingsController())->handleGet($req);

        self::assertSame(200, $res->get_status());
        $data = $res->get_data();
        self::assertArrayHasKey('slack_webhook_url', $data);
        self::assertNull($data['slack_webhook_url']);
    }

    // -------------------------------------------------------------------------
    // POST /settings/slack-webhook — host allowlist (SSRF guard)
    // -------------------------------------------------------------------------

    public function testPostRejectsNonSlackHost(): void
    {
        $req = new WP_REST_Request('POST', '/defyn/v1/settings/slack-webhook');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_body(json_encode(['webhook_url' => 'https://evil.example.com/x']));
        $req->set_header('Content-Type', 'application/json');
        $res = (new SettingsController())->handleSet($req);
        self::assertSame(400, $res->get_status());
        self::assertSame('settings.invalid_webhook', $res->get_data()['error']['code']);
    }

    public function testPostRejectsHttpScheme(): void
    {
        $req = new WP_REST_Request('POST', '/defyn/v1/settings/slack-webhook');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_body(json_encode(['webhook_url' => 'http://hooks.slack.com/services/T/B/x']));
        $req->set_header('Content-Type', 'application/json');
        $res = (new SettingsController())->handleSet($req);
        self::assertSame(400, $res->get_status());
        self::assertSame('settings.invalid_webhook', $res->get_data()['error']['code']);
    }

    // -------------------------------------------------------------------------
    // POST → GET round-trip
    // -------------------------------------------------------------------------

    public function testPostAcceptsSlackAndGetReturnsIt(): void
    {
        $req = new WP_REST_Request('POST', '/defyn/v1/settings/slack-webhook');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_body(json_encode(['webhook_url' => 'https://hooks.slack.com/services/T/B/x']));
        $req->set_header('Content-Type', 'application/json');
        self::assertSame(200, (new SettingsController())->handleSet($req)->get_status());

        $get = new WP_REST_Request('GET', '/defyn/v1/settings');
        $get->set_param('_authenticated_user_id', 1);
        self::assertSame(
            'https://hooks.slack.com/services/T/B/x',
            (new SettingsController())->handleGet($get)->get_data()['slack_webhook_url']
        );
    }

    // -------------------------------------------------------------------------
    // POST with empty string — clears the webhook, GET returns null
    // -------------------------------------------------------------------------

    public function testPostWithEmptyStringClearsWebhook(): void
    {
        // Seed a value first.
        $set = new WP_REST_Request('POST', '/defyn/v1/settings/slack-webhook');
        $set->set_param('_authenticated_user_id', 1);
        $set->set_body(json_encode(['webhook_url' => 'https://hooks.slack.com/services/T/B/x']));
        $set->set_header('Content-Type', 'application/json');
        (new SettingsController())->handleSet($set);

        // Clear it.
        $clear = new WP_REST_Request('POST', '/defyn/v1/settings/slack-webhook');
        $clear->set_param('_authenticated_user_id', 1);
        $clear->set_body(json_encode(['webhook_url' => '']));
        $clear->set_header('Content-Type', 'application/json');
        $res = (new SettingsController())->handleSet($clear);
        self::assertSame(200, $res->get_status());
        self::assertNull($res->get_data()['slack_webhook_url']);

        // GET must confirm null.
        $get = new WP_REST_Request('GET', '/defyn/v1/settings');
        $get->set_param('_authenticated_user_id', 1);
        self::assertNull((new SettingsController())->handleGet($get)->get_data()['slack_webhook_url']);
    }

    // -------------------------------------------------------------------------
    // User isolation — operator A's webhook must not be visible to operator B
    // -------------------------------------------------------------------------

    public function testWebhookIsScopedPerUser(): void
    {
        $userA = self::factory()->user->create();
        $userB = self::factory()->user->create();

        // Set for A.
        $set = new WP_REST_Request('POST', '/defyn/v1/settings/slack-webhook');
        $set->set_param('_authenticated_user_id', $userA);
        $set->set_body(json_encode(['webhook_url' => 'https://hooks.slack.com/services/A/A/a']));
        $set->set_header('Content-Type', 'application/json');
        (new SettingsController())->handleSet($set);

        // B should see null.
        $get = new WP_REST_Request('GET', '/defyn/v1/settings');
        $get->set_param('_authenticated_user_id', $userB);
        self::assertNull((new SettingsController())->handleGet($get)->get_data()['slack_webhook_url']);
    }

    // -------------------------------------------------------------------------
    // POST response shape — 200 with the saved URL returned immediately
    // -------------------------------------------------------------------------

    public function testPostResponseContainsSavedUrl(): void
    {
        $url = 'https://hooks.slack.com/services/T/C/z';
        $req = new WP_REST_Request('POST', '/defyn/v1/settings/slack-webhook');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_body(json_encode(['webhook_url' => $url]));
        $req->set_header('Content-Type', 'application/json');
        $res = (new SettingsController())->handleSet($req);
        self::assertSame(200, $res->get_status());
        self::assertSame($url, $res->get_data()['slack_webhook_url']);
    }

    // -------------------------------------------------------------------------
    // Activity log — URL must NEVER appear; only {cleared: bool} is recorded
    // -------------------------------------------------------------------------

    public function testActivityLogDoesNotContainWebhookUrl(): void
    {
        global $wpdb;

        $url = 'https://hooks.slack.com/services/T/SECRET/token';
        $req = new WP_REST_Request('POST', '/defyn/v1/settings/slack-webhook');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_body(json_encode(['webhook_url' => $url]));
        $req->set_header('Content-Type', 'application/json');
        (new SettingsController())->handleSet($req);

        $row = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log
             WHERE event_type = 'settings.slack_webhook_updated'
             ORDER BY id DESC LIMIT 1",
            ARRAY_A
        );

        self::assertNotNull($row, 'Activity log row must exist');

        // The URL must NEVER appear in the stored details.
        self::assertStringNotContainsString(
            'hooks.slack.com',
            (string) ($row['details'] ?? ''),
            'Webhook URL must not be stored in the activity log'
        );

        $details = json_decode($row['details'] ?? '{}', true);
        self::assertArrayHasKey('cleared', $details);
        self::assertFalse($details['cleared']);
    }

    public function testActivityLogRecordsClearedTrueOnEmpty(): void
    {
        global $wpdb;

        $req = new WP_REST_Request('POST', '/defyn/v1/settings/slack-webhook');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_body(json_encode(['webhook_url' => '']));
        $req->set_header('Content-Type', 'application/json');
        (new SettingsController())->handleSet($req);

        $row = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log
             WHERE event_type = 'settings.slack_webhook_updated'
             ORDER BY id DESC LIMIT 1",
            ARRAY_A
        );

        self::assertNotNull($row);
        $details = json_decode($row['details'] ?? '{}', true);
        self::assertTrue($details['cleared']);
    }
}
