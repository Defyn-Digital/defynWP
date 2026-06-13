<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\UpdateSitePlugin;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * P2.9 — bulk-job item lifecycle marks inside UpdateSitePlugin::handle.
 *
 * Mirrors UpdateSitePluginTest's MockHttpClient harness; asserts ONLY the
 * new $jobItemId behavior (the existing tests keep covering the per-site
 * row transitions).
 *
 * @group integration
 */
final class UpdateSitePluginBulkJobItemTest extends AbstractSchemaTestCase
{
    private BulkJobsRepository $bulkJobs;

    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_site_plugins');
        $this->freshlyActivate('defyn_activity_log');
        $this->freshlyActivate('defyn_bulk_jobs');
        $this->freshlyActivate('defyn_bulk_job_items');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . SitePluginsTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_job_items");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_jobs");
        // phpcs:enable WordPress.DB.PreparedSQL

        $this->bulkJobs = new BulkJobsRepository();
    }

    /** Active site with real Vault-encrypted dashboard key (UpdateSiteThemeTest pattern). */
    private function makeActiveSite(): int
    {
        $repo    = new SitesRepository();
        $vault   = new Vault(DEFYN_VAULT_KEY);
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

    private function seedAkismetRow(int $siteId): void
    {
        global $wpdb;
        $wpdb->insert(SitePluginsTable::tableName(), [
            'site_id'          => $siteId,
            'slug'             => 'akismet',
            'name'             => 'Akismet',
            'version'          => '5.3',
            'update_available' => 1,
            'update_version'   => '5.3.1',
            'update_state'     => 'queued',
            'last_seen_at'     => '2026-06-09 05:00:00',
            'created_at'       => '2026-06-09 05:00:00',
            'updated_at'       => '2026-06-09 05:00:00',
        ]);
    }

    private function makeJobItem(int $siteId, string $slug = 'akismet'): int
    {
        $jobId = $this->bulkJobs->createJob(1, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $items = $this->bulkJobs->createItems($jobId, [['site_id' => $siteId, 'slug' => $slug]], '2026-06-09 21:00:00');
        return $items[0]['item_id'];
    }

    private function itemRow(int $itemId): array
    {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}defyn_bulk_job_items WHERE id = %d", $itemId),
            ARRAY_A
        );
    }

    private function makeJob(callable $responseFactory): UpdateSitePlugin
    {
        return new UpdateSitePlugin(
            new SitesRepository(),
            new SitePluginsRepository(),
            new SignedHttpClient(new MockHttpClient($responseFactory)),
            new ActivityLogger(),
            new Vault(DEFYN_VAULT_KEY),
        );
    }

    public function testItemMarkedStartedAndBusyRetryReschedulesWithItemId(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAkismetRow($siteId);
        $itemId = $this->makeJobItem($siteId);

        $body = (string) json_encode(['error' => ['code' => 'plugins.update_in_progress', 'message' => 'busy']]);
        $job  = $this->makeJob(fn () => new MockResponse($body, [
            'http_code'        => 409,
            'response_headers' => ['content-type: application/json'],
        ]));

        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $job->handle($siteId, 'akismet', 0, $itemId);

        // Item entered `started` at handle entry and STAYS started across the
        // retry (retry-rescheduling must NOT mark terminal).
        $item = $this->itemRow($itemId);
        self::assertSame('started', $item['state']);
        self::assertNotNull($item['started_at']);
        self::assertNull($item['completed_at']);

        // The rescheduled action carries the SAME item id as 4th arg.
        self::assertCount(1, $scheduled);
        self::assertSame(UpdateSitePlugin::HOOK, $scheduled[0]['hook']);
        self::assertSame([$siteId, 'akismet', 1, $itemId], $scheduled[0]['args']);
    }

    public function testItemMarkedSucceededOnSuccess(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAkismetRow($siteId);
        $itemId = $this->makeJobItem($siteId);

        $body = (string) json_encode([
            'success'          => true,
            'slug'             => 'akismet',
            'previous_version' => '5.3',
            'new_version'      => '5.3.1',
            'server_time'      => time(),
        ]);
        $job = $this->makeJob(fn () => new MockResponse($body, [
            'http_code'        => 200,
            'response_headers' => ['content-type: application/json'],
        ]));

        $job->handle($siteId, 'akismet', 0, $itemId);

        $item = $this->itemRow($itemId);
        self::assertSame('succeeded', $item['state']);
        self::assertNotNull($item['completed_at']);

        // Single-item job — refreshJobTimestamps finalizes the parent too.
        global $wpdb;
        $jobRow = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}defyn_bulk_jobs WHERE id = %d", (int) $item['job_id']),
            ARRAY_A
        );
        self::assertNotNull($jobRow['started_at']);
        self::assertNotNull($jobRow['completed_at']);
    }

    public function testItemMarkedFailedOnConnectorFailure(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAkismetRow($siteId);
        $itemId = $this->makeJobItem($siteId);

        $body = (string) json_encode([
            'error' => ['code' => 'plugins.update_failed', 'message' => 'Could not copy file.'],
        ]);
        $job = $this->makeJob(fn () => new MockResponse($body, [
            'http_code'        => 502,
            'response_headers' => ['content-type: application/json'],
        ]));

        $job->handle($siteId, 'akismet', 0, $itemId);

        $item = $this->itemRow($itemId);
        self::assertSame('failed', $item['state']);
        self::assertStringContainsString('Could not copy file', $item['error_message']);
        self::assertNotNull($item['completed_at']);
    }

    public function testItemMarkedFailedWhenBusyAfterFiveRetries(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAkismetRow($siteId);
        $itemId = $this->makeJobItem($siteId);

        $body = (string) json_encode(['error' => ['code' => 'plugins.update_in_progress', 'message' => 'busy']]);
        $job  = $this->makeJob(fn () => new MockResponse($body, [
            'http_code'        => 409,
            'response_headers' => ['content-type: application/json'],
        ]));

        $job->handle($siteId, 'akismet', 5, $itemId);

        $item = $this->itemRow($itemId);
        self::assertSame('failed', $item['state']);
        self::assertStringContainsString('busy after 5 retries', $item['error_message']);
    }

    public function testItemMarkedFailedWhenSiteRowMissing(): void
    {
        $itemId = $this->makeJobItem(999999);

        $job = $this->makeJob(fn () => new MockResponse('{}', ['http_code' => 200]));
        $job->handle(999999, 'akismet', 0, $itemId);

        $item = $this->itemRow($itemId);
        self::assertSame('failed', $item['state']);
        self::assertStringContainsString('Site no longer exists', $item['error_message']);
    }

    public function testNoBulkRowsTouchedWhenJobItemIdIsZero(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAkismetRow($siteId);
        $unrelatedItemId = $this->makeJobItem($siteId);

        $body = (string) json_encode([
            'success'          => true,
            'slug'             => 'akismet',
            'previous_version' => '5.3',
            'new_version'      => '5.3.1',
            'server_time'      => time(),
        ]);
        $job = $this->makeJob(fn () => new MockResponse($body, [
            'http_code'        => 200,
            'response_headers' => ['content-type: application/json'],
        ]));

        // Backwards-compat: 3-arg call (pre-v0.9.0 AS rows / per-site P2.2 endpoint).
        $job->handle($siteId, 'akismet', 0);

        $item = $this->itemRow($unrelatedItemId);
        self::assertSame('queued', $item['state'], 'jobItemId=0 must not touch any bulk rows');
        self::assertNull($item['started_at']);
    }
}
