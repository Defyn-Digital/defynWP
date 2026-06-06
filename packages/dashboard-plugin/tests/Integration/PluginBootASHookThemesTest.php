<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Jobs\RefreshSiteThemes;
use Defyn\Dashboard\Jobs\SyncSite;
use Defyn\Dashboard\Jobs\UpdateSiteTheme;
use Defyn\Dashboard\Plugin;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SyncPluginsService;
use Defyn\Dashboard\Services\SyncService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Defyn\Dashboard\Http\SignedHttpClient;

final class PluginBootASHookThemesTest extends AbstractSchemaTestCase
{
    public function testRefreshSiteThemesHookRegistered(): void
    {
        Plugin::instance()->boot();
        $this->assertNotFalse(has_action(RefreshSiteThemes::HOOK));
    }

    public function testUpdateSiteThemeHookRegistered(): void
    {
        Plugin::instance()->boot();
        $this->assertNotFalse(has_action(UpdateSiteTheme::HOOK));
    }

    public function testSyncSiteAlsoSchedulesThemesRefresh(): void
    {
        \Defyn\Dashboard\Activation::activate();

        $keypair = sodium_crypto_sign_keypair();
        $privateKey = base64_encode(sodium_crypto_sign_secretkey($keypair));
        $vault = new Vault(DEFYN_VAULT_KEY);
        $encrypted = $vault->encrypt($privateKey);

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id' => 1, 'url' => 'https://smartcoding.test', 'label' => 'Smart',
            'status' => 'active', 'our_private_key' => $encrypted,
            'site_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'created_at' => '2026-06-06 00:00:00',
        ]);
        $siteId = (int) $wpdb->insert_id;

        $statusBody  = json_encode(['ok' => true, 'wp_version' => '6.6', 'php_version' => '8.2']);
        $pluginsBody = json_encode(['plugins' => [], 'truncated' => false, 'server_time' => time()]);
        $factory = function (string $method, string $url) use ($statusBody, $pluginsBody) {
            if (str_contains($url, '/plugins')) {
                return new MockResponse($pluginsBody, ['http_code' => 200]);
            }
            return new MockResponse($statusBody, ['http_code' => 200]);
        };

        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $sync = new SyncSite(
            new SyncService(),
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new SyncPluginsService(),
            new ActivityLogger(),
        );
        $sync->handle($siteId);

        $hooks = array_column($scheduled, 'hook');
        $this->assertContains('defyn_refresh_site_themes', $hooks);
    }
}
