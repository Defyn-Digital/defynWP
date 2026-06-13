<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\UpdateSiteTheme;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Schema\SiteThemesTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\ThemesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * P2.9 — bulk-job item lifecycle marks inside UpdateSiteTheme::handle,
 * including the theme-specific 409 themes.no_update_available
 * success-by-other-means path (MUST mark the item succeeded).
 *
 * @group integration
 */
final class UpdateSiteThemeBulkJobItemTest extends AbstractSchemaTestCase
{
    private BulkJobsRepository $bulkJobs;

    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_site_themes');
        $this->freshlyActivate('defyn_activity_log');
        $this->freshlyActivate('defyn_bulk_jobs');
        $this->freshlyActivate('defyn_bulk_job_items');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . SiteThemesTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_job_items");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_jobs");
        // phpcs:enable WordPress.DB.PreparedSQL

        $this->bulkJobs = new BulkJobsRepository();
    }

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

    private function seedAstraRow(int $siteId): void
    {
        global $wpdb;
        $wpdb->insert(SiteThemesTable::tableName(), [
            'site_id'          => $siteId,
            'slug'             => 'astra',
            'name'             => 'Astra',
            'version'          => '4.6.3',
            'parent_slug'      => null,
            'is_active'        => 1,
            'update_available' => 1,
            'update_version'   => '4.7.0',
            'update_state'     => 'queued',
            'last_seen_at'     => '2026-06-09 05:00:00',
            'created_at'       => '2026-06-09 05:00:00',
            'updated_at'       => '2026-06-09 05:00:00',
        ]);
    }

    private function makeJobItem(int $siteId): int
    {
        $jobId = $this->bulkJobs->createJob(1, 'theme_update', 1, 0, '2026-06-09 21:00:00');
        $items = $this->bulkJobs->createItems($jobId, [['site_id' => $siteId, 'slug' => 'astra']], '2026-06-09 21:00:00');
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

    private function makeJob(callable $responseFactory): UpdateSiteTheme
    {
        return new UpdateSiteTheme(
            new SitesRepository(),
            new ThemesRepository(),
            new SignedHttpClient(new MockHttpClient($responseFactory)),
            new ActivityLogger(),
            new Vault(DEFYN_VAULT_KEY),
        );
    }

    public function testItemMarkedStartedAndBusyRetryReschedulesWithItemId(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAstraRow($siteId);
        $itemId = $this->makeJobItem($siteId);

        $body = (string) json_encode(['error' => ['code' => 'connector.upgrade_in_progress', 'message' => 'busy']]);
        $job  = $this->makeJob(fn () => new MockResponse($body, [
            'http_code'        => 409,
            'response_headers' => ['content-type: application/json'],
        ]));

        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $job->handle($siteId, 'astra', 0, $itemId);

        $item = $this->itemRow($itemId);
        self::assertSame('started', $item['state']);
        self::assertNull($item['completed_at']);
        self::assertSame([$siteId, 'astra', 1, $itemId], $scheduled[0]['args']);
    }

    public function testItemMarkedSucceededOnSuccess(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAstraRow($siteId);
        $itemId = $this->makeJobItem($siteId);

        $body = (string) json_encode([
            'success'          => true,
            'slug'             => 'astra',
            'previous_version' => '4.6.3',
            'new_version'      => '4.7.0',
            'server_time'      => time(),
        ]);
        $job = $this->makeJob(fn () => new MockResponse($body, [
            'http_code'        => 200,
            'response_headers' => ['content-type: application/json'],
        ]));

        $job->handle($siteId, 'astra', 0, $itemId);

        $item = $this->itemRow($itemId);
        self::assertSame('succeeded', $item['state']);
        self::assertNotNull($item['completed_at']);
    }

    public function testItemMarkedFailedOnConnectorFailure(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAstraRow($siteId);
        $itemId = $this->makeJobItem($siteId);

        $body = (string) json_encode([
            'error' => ['code' => 'themes.update_failed', 'message' => 'Could not copy file.'],
        ]);
        $job = $this->makeJob(fn () => new MockResponse($body, [
            'http_code'        => 502,
            'response_headers' => ['content-type: application/json'],
        ]));

        $job->handle($siteId, 'astra', 0, $itemId);

        $item = $this->itemRow($itemId);
        self::assertSame('failed', $item['state']);
        self::assertStringContainsString('Could not copy file', $item['error_message']);
    }

    public function testItemMarkedSucceededOnNoUpdateAvailable409(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAstraRow($siteId);
        $itemId = $this->makeJobItem($siteId);

        // 409 success-by-other-means — someone already upgraded the theme.
        // The bulk-job item's goal is achieved: MUST count as succeeded.
        $body = (string) json_encode(['error' => ['code' => 'themes.no_update_available', 'message' => 'No update available for "astra".']]);
        $job  = $this->makeJob(fn () => new MockResponse($body, ['http_code' => 409]));

        $job->handle($siteId, 'astra', 0, $itemId);

        $item = $this->itemRow($itemId);
        self::assertSame('succeeded', $item['state']);
        self::assertNotNull($item['completed_at']);
        self::assertNull($item['error_message']);
    }

    public function testNoBulkRowsTouchedWhenJobItemIdIsZero(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAstraRow($siteId);
        $unrelatedItemId = $this->makeJobItem($siteId);

        $body = (string) json_encode([
            'success'          => true,
            'slug'             => 'astra',
            'previous_version' => '4.6.3',
            'new_version'      => '4.7.0',
            'server_time'      => time(),
        ]);
        $job = $this->makeJob(fn () => new MockResponse($body, [
            'http_code'        => 200,
            'response_headers' => ['content-type: application/json'],
        ]));

        $job->handle($siteId, 'astra', 0);

        $item = $this->itemRow($unrelatedItemId);
        self::assertSame('queued', $item['state']);
        self::assertNull($item['started_at']);
    }
}
