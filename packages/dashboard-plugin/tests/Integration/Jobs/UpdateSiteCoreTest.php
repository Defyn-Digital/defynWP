<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\UpdateSiteCore;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @group integration
 */
final class UpdateSiteCoreTest extends AbstractSchemaTestCase
{
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_activity_log');

        $keypair = sodium_crypto_sign_keypair();
        $privateKey = base64_encode(sodium_crypto_sign_secretkey($keypair));
        $vault = new Vault(DEFYN_VAULT_KEY);
        $encrypted = $vault->encrypt($privateKey);

        global $wpdb;
        $wpdb->insert(SitesTable::tableName(), [
            'user_id'                 => 1,
            'url'                     => 'https://smartcoding.test',
            'label'                   => 'Smart',
            'status'                  => 'active',
            'our_private_key'         => $encrypted,
            'site_public_key'         => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'wp_version'              => '7.0',
            'php_version'             => '8.3.31',
            'plugin_counts'           => '{"installed":0,"active":0}',
            'theme_counts'            => '{"installed":0,"active":0}',
            'ssl_status'              => 'enabled',
            'ssl_expires_at'          => null,
            'last_sync_at'            => '2026-06-07 04:00:00',
            'last_contact_at'         => '2026-06-07 04:00:00',
            'created_at'              => '2026-06-06 00:00:00',
            'updated_at'              => '2026-06-07 04:00:00',
            'core_update_available'   => 1,
            'core_update_version'     => '7.0.1',
            'core_update_state'       => 'queued',
            'last_core_update_error'  => null,
        ]);
        $this->siteId = (int) $wpdb->insert_id;
    }

    public function testSuccessPathMarksIdleAndBumpsVersion(): void
    {
        $body = json_encode([
            'success'          => true,
            'previous_version' => '7.0',
            'new_version'      => '7.0.1',
            'server_time'      => time(),
        ]);

        $captured = null;
        $factory = function (string $method, string $url, array $options) use (&$captured, $body) {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];
            return new MockResponse($body, ['http_code' => 200]);
        };

        $job = new UpdateSiteCore(
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
        );

        $job->handle($this->siteId, 0);

        $this->assertSame('POST', $captured['method']);
        $this->assertSame(300, (int) $captured['options']['timeout']);
        $this->assertStringEndsWith('/wp-json/defyn-connector/v1/core/update', $captured['url']);

        $row = (new SitesRepository())->findById($this->siteId);
        $this->assertSame('idle', $row->coreUpdateState);
        $this->assertSame('7.0.1', $row->wpVersion);
        $this->assertFalse($row->coreUpdateAvailable);
        $this->assertNull($row->coreUpdateVersion);
    }

    public function testTripletStartedAndSucceededEventsLoggedInOrder(): void
    {
        $body = json_encode([
            'success'          => true,
            'previous_version' => '7.0',
            'new_version'      => '7.0.1',
            'server_time'      => time(),
        ]);
        $factory = fn () => new MockResponse($body, ['http_code' => 200]);

        $job = new UpdateSiteCore(
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
        );

        $job->handle($this->siteId, 0);

        global $wpdb;
        $events = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT event_type FROM {$wpdb->prefix}defyn_activity_log
                 WHERE site_id = %d AND event_type LIKE 'core_update%%'
                 ORDER BY id ASC",
                $this->siteId
            )
        );
        $this->assertSame(['core_update.started', 'core_update.succeeded'], $events);
    }

    public function testTimeoutConstantIsThreeHundred(): void
    {
        $this->assertSame(300, UpdateSiteCore::TIMEOUT_SECONDS);
    }

    public function testNoUpdateAvailable409TreatedAsSuccessByOtherMeans(): void
    {
        $body = json_encode(['error' => ['code' => 'core.no_update_available', 'message' => 'WordPress reports no core update available.']]);
        $factory = fn () => new MockResponse($body, ['http_code' => 409]);
        $job = new UpdateSiteCore(
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
        );

        $job->handle($this->siteId, 0);

        $row = (new SitesRepository())->findById($this->siteId);

        $this->assertSame('idle', $row->coreUpdateState);
        $this->assertSame('7.0', $row->wpVersion);
        $this->assertFalse($row->coreUpdateAvailable);
        $this->assertNull($row->coreUpdateVersion);

        global $wpdb;
        $event = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT event_type, details FROM {$wpdb->prefix}defyn_activity_log
                 WHERE site_id = %d AND event_type = 'core_update.succeeded_no_change'
                 ORDER BY id DESC LIMIT 1",
                $this->siteId
            ),
            ARRAY_A,
        );
        $this->assertNotNull($event);
        $details = json_decode((string) $event['details'], true);
        $this->assertSame('7.0', $details['current_version']);
    }

    public function testMajorBlocked409MarksFailedImmediatelyNoRetry(): void
    {
        $body = json_encode(['error' => ['code' => 'core.major_update_blocked', 'message' => 'Major-version updates (7.0 -> 8.0) require P2.4.1.']]);
        $factory = fn () => new MockResponse($body, ['http_code' => 409]);

        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $job = new UpdateSiteCore(
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
        );

        $job->handle($this->siteId, 0);

        $row = (new SitesRepository())->findById($this->siteId);
        $this->assertSame('failed', $row->coreUpdateState);
        $this->assertStringContainsString('Major-version updates', $row->lastCoreUpdateError ?? '');

        $this->assertEmpty($scheduled);

        global $wpdb;
        $event = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT event_type FROM {$wpdb->prefix}defyn_activity_log
                 WHERE site_id = %d AND event_type = 'core_update.blocked_major'
                 ORDER BY id DESC LIMIT 1",
                $this->siteId
            ),
            ARRAY_A,
        );
        $this->assertNotNull($event);
    }

    public function testInProgress409ReschedulesWithExponentialBackoff(): void
    {
        $body = json_encode(['error' => ['code' => 'connector.upgrade_in_progress', 'message' => 'busy']]);
        $factory = fn () => new MockResponse($body, ['http_code' => 409]);
        $job = new UpdateSiteCore(
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
        );

        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['when' => $when, 'hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $job->handle($this->siteId, 0);
        $job->handle($this->siteId, 1);
        $job->handle($this->siteId, 2);

        $this->assertCount(3, $scheduled);

        $now = time();
        $this->assertEqualsWithDelta($now + 60, $scheduled[0]['when'], 5);
        $this->assertEqualsWithDelta($now + 120, $scheduled[1]['when'], 5);
        $this->assertEqualsWithDelta($now + 240, $scheduled[2]['when'], 5);
        $this->assertSame([$this->siteId, 1], $scheduled[0]['args']);
        $this->assertSame([$this->siteId, 2], $scheduled[1]['args']);
        $this->assertSame([$this->siteId, 3], $scheduled[2]['args']);

        $row = (new SitesRepository())->findById($this->siteId);
        $this->assertSame('updating', $row->coreUpdateState);

        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log
                 WHERE site_id = %d AND event_type = 'core_update.retry'",
                $this->siteId
            )
        );
        $this->assertSame(3, $count);
    }

    public function testFifthRetryExhaustionMarksFailedWithRetryExhaustedCode(): void
    {
        $body = json_encode(['error' => ['code' => 'connector.upgrade_in_progress', 'message' => 'busy']]);
        $factory = fn () => new MockResponse($body, ['http_code' => 409]);
        $job = new UpdateSiteCore(
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
        );

        $job->handle($this->siteId, 5);

        $row = (new SitesRepository())->findById($this->siteId);
        $this->assertSame('failed', $row->coreUpdateState);
        $this->assertStringContainsString('busy after 5 retries', $row->lastCoreUpdateError ?? '');

        global $wpdb;
        $details = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT details FROM {$wpdb->prefix}defyn_activity_log
                 WHERE site_id = %d AND event_type = 'core_update.failed' ORDER BY id DESC LIMIT 1",
                $this->siteId
            )
        );
        $decoded = json_decode((string) $details, true);
        $this->assertSame('retry_exhausted', $decoded['error_code']);
    }

    public function testTransportErrorMarksFailedNoRetry(): void
    {
        $factory = fn () => throw new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused');

        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $job = new UpdateSiteCore(
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
        );

        $job->handle($this->siteId, 0);

        $row = (new SitesRepository())->findById($this->siteId);
        $this->assertSame('failed', $row->coreUpdateState);
        $this->assertStringContainsString('Connection refused', $row->lastCoreUpdateError ?? '');

        $this->assertEmpty($scheduled);
    }

    public function testConnectorUpdateFailed502MarksFailedNoRetry(): void
    {
        $body = json_encode(['error' => ['code' => 'core.update_failed', 'message' => 'Could not copy file. /wp-admin/index.php']]);
        $factory = fn () => new MockResponse($body, ['http_code' => 502]);

        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $job = new UpdateSiteCore(
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
        );

        $job->handle($this->siteId, 0);

        $row = (new SitesRepository())->findById($this->siteId);
        $this->assertSame('failed', $row->coreUpdateState);
        $this->assertStringContainsString('Could not copy file', $row->lastCoreUpdateError ?? '');

        $this->assertEmpty($scheduled);

        global $wpdb;
        $msg = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT JSON_EXTRACT(details, '$.error_message') FROM {$wpdb->prefix}defyn_activity_log
                 WHERE site_id = %d AND event_type = 'core_update.failed' ORDER BY id DESC LIMIT 1",
                $this->siteId
            )
        );
        $this->assertStringContainsString('Could not copy file', (string) $msg);
    }
}
