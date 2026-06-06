<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\UpdateSiteTheme;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SiteThemesTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\ThemesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @group integration
 */
final class UpdateSiteThemeTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_site_themes');
        $this->freshlyActivate('defyn_activity_log');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . SiteThemesTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
    }

    /** Active site with real Vault-encrypted dashboard key. */
    private function makeActiveSite(): int
    {
        $repo  = new SitesRepository();
        $vault = new Vault(DEFYN_VAULT_KEY);
        // Use a real Ed25519 secret (64 bytes) — SignedHttpClient::signedPostJson
        // will base64-decode it and call sodium_crypto_sign_detached.
        $keypair = sodium_crypto_sign_keypair();
        $priv    = base64_encode(sodium_crypto_sign_secretkey($keypair));
        $id      = $repo->insertPending(
            userId: 1,
            url: 'https://smartcoding.test',
            label: 'Smart',
            ourPublicKey: base64_encode(sodium_crypto_sign_publickey($keypair)),
            ourPrivateKeyEncrypted: $vault->encrypt($priv),
        );
        $repo->markActive($id, base64_encode(random_bytes(32)));
        return $id;
    }

    private function seedTwentyfivRow(int $siteId): void
    {
        global $wpdb;
        $wpdb->insert(SiteThemesTable::tableName(), [
            'site_id'                   => $siteId,
            'slug'                      => 'twentytwentyfive',
            'name'                      => 'Twenty Twenty-Five',
            'version'                   => '1.2',
            'parent_slug'               => null,
            'is_active'                 => 1,
            'update_available'          => 1,
            'update_version'            => '1.3',
            'update_state'              => 'queued',
            'last_update_error'         => null,
            'last_update_attempt_at'    => '2026-06-06 09:00:00',
            'last_seen_at'              => '2026-06-06 05:00:00',
            'created_at'                => '2026-06-06 05:00:00',
            'updated_at'                => '2026-06-06 05:00:00',
        ]);
    }

    public function testSuccessPathMarksIdleAndBumpsVersion(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedTwentyfivRow($siteId);

        $successBody = (string) json_encode([
            'success'          => true,
            'slug'             => 'twentytwentyfive',
            'previous_version' => '1.2',
            'new_version'      => '1.3',
            'server_time'      => time(),
        ]);

        $captured = null;
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured, $successBody) {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];
            return new MockResponse(
                $successBody,
                [
                    'http_code'        => 200,
                    'response_headers' => ['content-type: application/json'],
                ],
            );
        });

        $job = new UpdateSiteTheme(
            new SitesRepository(),
            new ThemesRepository(),
            new SignedHttpClient($mock),
            new ActivityLogger(),
            new Vault(DEFYN_VAULT_KEY),
        );

        $job->handle($siteId, 'twentytwentyfive', 0);

        // Connector was called with 120s timeout.
        self::assertNotNull($captured);
        self::assertSame('POST', $captured['method']);
        self::assertEquals(120, $captured['options']['timeout']);
        self::assertStringEndsWith('/wp-json/defyn-connector/v1/themes/twentytwentyfive/update', $captured['url']);

        // Row was marked idle + bumped to 1.3
        $repo = new ThemesRepository();
        $row  = $repo->findRowForSiteAndSlug($siteId, 'twentytwentyfive');
        self::assertNotNull($row);
        self::assertSame('idle', $row['update_state']);
        self::assertSame('1.3', $row['version']);
        self::assertSame('0', $row['update_available']);
        self::assertNull($row['update_version']);

        // theme_update.started + theme_update.succeeded in activity log
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT event_type FROM ' . ActivityLogTable::tableName() .
            " WHERE site_id = {$siteId} ORDER BY id ASC",
            ARRAY_A,
        );
        $types = array_column($rows, 'event_type');
        self::assertSame(['theme_update.started', 'theme_update.succeeded'], $types);
    }

    public function testInProgress409ReschedulesWithExponentialBackoff(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedTwentyfivRow($siteId);

        $body    = (string) json_encode(['error' => ['code' => 'connector.upgrade_in_progress', 'message' => 'busy']]);
        $factory = fn () => new MockResponse($body, [
            'http_code'        => 409,
            'response_headers' => ['content-type: application/json'],
        ]);
        $job = new UpdateSiteTheme(
            new SitesRepository(),
            new ThemesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
            new Vault(DEFYN_VAULT_KEY),
        );

        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['when' => $when, 'hook' => $hook, 'args' => $args];
            return 999; // pretend AS returned an action ID
        }, 10, 4);

        $job->handle($siteId, 'twentytwentyfive', 0);
        $job->handle($siteId, 'twentytwentyfive', 1);
        $job->handle($siteId, 'twentytwentyfive', 2);

        self::assertCount(3, $scheduled);

        // Backoff: 60, 120, 240 seconds from now
        $now = time();
        self::assertEqualsWithDelta($now + 60, $scheduled[0]['when'], 5);
        self::assertEqualsWithDelta($now + 120, $scheduled[1]['when'], 5);
        self::assertEqualsWithDelta($now + 240, $scheduled[2]['when'], 5);

        // Each schedule increments the attempt arg
        self::assertSame([$siteId, 'twentytwentyfive', 1], $scheduled[0]['args']);
        self::assertSame([$siteId, 'twentytwentyfive', 2], $scheduled[1]['args']);
        self::assertSame([$siteId, 'twentytwentyfive', 3], $scheduled[2]['args']);

        // Row stays in 'updating' across retries (don't flip to failed yet)
        $row = (new ThemesRepository())->findRowForSiteAndSlug($siteId, 'twentytwentyfive');
        self::assertSame('updating', $row['update_state']);

        // theme_update.retry events logged
        global $wpdb;
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . ActivityLogTable::tableName() .
            " WHERE event_type = 'theme_update.retry'"
        );
        self::assertSame(3, $count);
    }

    public function testFifthRetryMarksFailed(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedTwentyfivRow($siteId);

        $body    = (string) json_encode(['error' => ['code' => 'connector.upgrade_in_progress', 'message' => 'busy']]);
        $factory = fn () => new MockResponse($body, [
            'http_code'        => 409,
            'response_headers' => ['content-type: application/json'],
        ]);
        $job = new UpdateSiteTheme(
            new SitesRepository(),
            new ThemesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
            new Vault(DEFYN_VAULT_KEY),
        );

        // attempt = 5 means we've exhausted retries
        $job->handle($siteId, 'twentytwentyfive', 5);

        $row = (new ThemesRepository())->findRowForSiteAndSlug($siteId, 'twentytwentyfive');
        self::assertSame('failed', $row['update_state']);
        self::assertStringContainsString('busy after 5 retries', $row['last_update_error']);
    }

    public function testTransportErrorMarksFailed(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedTwentyfivRow($siteId);

        // MockHttpClient with a factory closure that throws TransportException →
        // SignedHttpClient catches and returns ['status' => 0, 'error' => '<msg>'].
        $factory = fn () => throw new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused');
        $job = new UpdateSiteTheme(
            new SitesRepository(),
            new ThemesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
            new Vault(DEFYN_VAULT_KEY),
        );

        $job->handle($siteId, 'twentytwentyfive', 0);

        $row = (new ThemesRepository())->findRowForSiteAndSlug($siteId, 'twentytwentyfive');
        self::assertSame('failed', $row['update_state']);
        self::assertStringContainsString('Connection refused', $row['last_update_error']);
    }

    public function testUpgradeFailedFromConnectorMarksFailed(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedTwentyfivRow($siteId);

        $body    = (string) json_encode([
            'error' => [
                'code'    => 'themes.update_failed',
                'message' => 'Could not copy file. /wp-content/themes/twentytwentyfive',
            ],
        ]);
        $factory = fn () => new MockResponse($body, [
            'http_code'        => 502,
            'response_headers' => ['content-type: application/json'],
        ]);
        $job = new UpdateSiteTheme(
            new SitesRepository(),
            new ThemesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
            new Vault(DEFYN_VAULT_KEY),
        );

        $job->handle($siteId, 'twentytwentyfive', 0);

        $row = (new ThemesRepository())->findRowForSiteAndSlug($siteId, 'twentytwentyfive');
        self::assertSame('failed', $row['update_state']);
        self::assertStringContainsString('Could not copy file', $row['last_update_error']);

        global $wpdb;
        $msg = $wpdb->get_var(
            "SELECT JSON_EXTRACT(details, '$.error_message') FROM " . ActivityLogTable::tableName() .
            " WHERE event_type = 'theme_update.failed' ORDER BY id DESC LIMIT 1"
        );
        self::assertStringContainsString('Could not copy file', (string) $msg);
    }

    public function testNoUpdateAvailable409TreatedAsSuccessByOtherMeans(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedTwentyfivRow($siteId);

        $body = json_encode(['error' => ['code' => 'themes.no_update_available', 'message' => 'No update available for "twentytwentyfive".']]);
        $factory = fn () => new MockResponse($body, ['http_code' => 409]);
        $job = new UpdateSiteTheme(
            new SitesRepository(),
            new ThemesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
            new Vault(DEFYN_VAULT_KEY),
        );

        // Row's current version before the attempt is 1.2 (from setUp).
        $job->handle($siteId, 'twentytwentyfive', 0);

        $row = (new ThemesRepository())->findRowForSiteAndSlug($siteId, 'twentytwentyfive');

        // Should be marked succeeded — pinned to the pre-attempt version, NOT the
        // connector's update_version (because no update actually happened).
        self::assertSame('idle', $row['update_state']);
        self::assertSame('1.2', $row['version']);
        self::assertSame('0', $row['update_available']);
        self::assertNull($row['update_version']);

        global $wpdb;
        $event = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT event_type, details FROM {$wpdb->prefix}defyn_activity_log
                 WHERE site_id = %d AND event_type = 'theme_update.succeeded_no_change'
                 ORDER BY id DESC LIMIT 1",
                $siteId
            ),
            ARRAY_A,
        );
        self::assertNotNull($event);
        $details = json_decode((string) $event['details'], true);
        self::assertSame('twentytwentyfive', $details['slug']);
        self::assertSame('1.2', $details['current_version']);
    }
}
