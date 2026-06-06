<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Jobs\UpdateSiteTheme;
use Defyn\Dashboard\Rest\Middleware\RateLimit;
use Defyn\Dashboard\Schema\SiteThemesTable;
use Defyn\Dashboard\Services\ThemesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

final class SitesThemesUpdateTest extends AbstractSchemaTestCase
{
    private int $userId;
    private string $token;
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::activate();

        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_%' OR option_name LIKE '_transient_timeout_defyn_rl_%'");
        wp_cache_flush();

        do_action('rest_api_init');

        $this->userId = self::factory()->user->create();
        $this->token = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($this->userId);

        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id' => $this->userId, 'url' => 'https://smartcoding.test',
            'label' => 'Smart', 'status' => 'active', 'site_public_key' => base64_encode(random_bytes(32)),
            'our_public_key' => base64_encode(random_bytes(32)), 'created_at' => '2026-06-06 00:00:00',
            'updated_at' => '2026-06-06 00:00:00',
        ]);
        $this->siteId = (int) $wpdb->insert_id;

        $wpdb->insert(SiteThemesTable::tableName(), [
            'site_id' => $this->siteId, 'slug' => 'twentytwentyfive',
            'name' => 'Twenty Twenty-Five', 'version' => '1.2',
            'parent_slug' => null, 'is_active' => 1,
            'update_available' => 1, 'update_version' => '1.3',
            'update_state' => 'idle', 'last_update_error' => null,
            'last_update_attempt_at' => null,
            'last_seen_at' => '2026-06-06 05:00:00',
            'created_at' => '2026-06-05 09:00:00', 'updated_at' => '2026-06-06 05:00:00',
        ]);
    }

    public function testSuccessReturns202AndSchedulesJob(): void
    {
        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/themes/twentytwentyfive/update"));

        $this->assertSame(202, $response->get_status());
        $body = $response->get_data();
        $this->assertTrue($body['scheduled']);
        $this->assertSame($this->siteId, $body['site_id']);
        $this->assertSame('twentytwentyfive', $body['slug']);
        $this->assertSame('queued', $body['update_state']);

        $row = (new ThemesRepository())->findRowForSiteAndSlug($this->siteId, 'twentytwentyfive');
        $this->assertSame('queued', $row['update_state']);

        $this->assertSame(UpdateSiteTheme::HOOK, $scheduled[0]['hook']);
        $this->assertSame([$this->siteId, 'twentytwentyfive', 0], $scheduled[0]['args']);

        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log
                 WHERE event_type = 'theme_update.requested' AND site_id = %d",
                $this->siteId
            )
        );
        $this->assertSame(1, $count);
    }

    public function testSiteNotOwnedReturns404(): void
    {
        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/99999/themes/twentytwentyfive/update"));
        $this->assertSame(404, $response->get_status());
        $this->assertSame('sites.not_found', $response->get_data()['error']['code']);
    }

    public function testThemeNotInInventoryReturns404(): void
    {
        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/themes/not-installed/update"));
        $this->assertSame(404, $response->get_status());
        $this->assertSame('themes.not_found_in_inventory', $response->get_data()['error']['code']);
    }

    public function testNoUpdateAvailableReturns409(): void
    {
        global $wpdb;
        $wpdb->update(SiteThemesTable::tableName(),
            ['update_available' => 0, 'update_version' => null],
            ['site_id' => $this->siteId, 'slug' => 'twentytwentyfive']);

        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/themes/twentytwentyfive/update"));
        $this->assertSame(409, $response->get_status());
        $this->assertSame('themes.no_update_available_for_slug', $response->get_data()['error']['code']);
    }

    public function testUpdateInProgressReturns409(): void
    {
        global $wpdb;
        $wpdb->update(SiteThemesTable::tableName(),
            ['update_state' => 'queued'],
            ['site_id' => $this->siteId, 'slug' => 'twentytwentyfive']);

        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/themes/twentytwentyfive/update"));
        $this->assertSame(409, $response->get_status());
        $this->assertSame('themes.update_in_progress', $response->get_data()['error']['code']);
    }

    public function testSeventhCallReturns429(): void
    {
        global $wpdb;
        for ($i = 2; $i <= 7; $i++) {
            $wpdb->insert(SiteThemesTable::tableName(), [
                'site_id' => $this->siteId, 'slug' => 'theme-' . $i,
                'name' => 'Theme ' . $i, 'version' => '1.0',
                'parent_slug' => null, 'is_active' => 0,
                'update_available' => 1, 'update_version' => '1.1',
                'update_state' => 'idle', 'last_update_error' => null,
                'last_update_attempt_at' => null,
                'last_seen_at' => '2026-06-06 05:00:00',
                'created_at' => '2026-06-05 09:00:00', 'updated_at' => '2026-06-06 05:00:00',
            ]);
        }

        $slugs = ['twentytwentyfive', 'theme-2', 'theme-3', 'theme-4', 'theme-5', 'theme-6'];
        foreach ($slugs as $slug) {
            $res = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/themes/{$slug}/update"));
            $this->assertSame(202, $res->get_status(), "call for {$slug} should pass");
        }

        $res = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/themes/theme-7/update"));
        $this->assertSame(429, $res->get_status());
        $this->assertSame('themes.rate_limited', $res->get_data()['error']['code']);
    }

    public function testThemesUpdateBucketIsSeparateFromPluginsUpdate(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $r = new WP_REST_Request('POST', "/defyn/v1/sites/{$this->siteId}/plugins/plugin-{$i}/update");
            $r->set_header('Authorization', 'Bearer ' . $this->token);
            $r->set_param('id', $this->siteId);
            $r->set_param('slug', 'plugin-' . $i);
            $r->set_param('_authenticated_user_id', $this->userId);
            $this->assertTrue(RateLimit::pluginsUpdate($r));
        }

        $r = new WP_REST_Request('POST', "/defyn/v1/sites/{$this->siteId}/themes/twentytwentyfive/update");
        $r->set_header('Authorization', 'Bearer ' . $this->token);
        $r->set_param('id', $this->siteId);
        $r->set_param('slug', 'twentytwentyfive');
        $r->set_param('_authenticated_user_id', $this->userId);
        $this->assertTrue(RateLimit::themesUpdate($r));
    }

    private function signed(string $method, string $path): WP_REST_Request
    {
        $request = new WP_REST_Request($method, $path);
        $request->set_header('Authorization', 'Bearer ' . $this->token);
        return $request;
    }
}
