<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\HealthService;
use Defyn\Dashboard\Services\IncidentService;
use Defyn\Dashboard\Services\IncidentsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * P3.1 Task 7 — HealthService opens/closes incidents via IncidentService.
 *
 * Verifies that:
 *   - Two consecutive transport failures open an incident (2-failure debounce)
 *   - A subsequent successful ping closes the open incident
 *   - A single failure followed by success produces no incident
 *   - The missing-private-key fast-fail branch also feeds IncidentService
 *   - Steady-state success (markContactAt path) resets the failure counter
 *
 * Guardrail 3: the existing markOffline / markRecovered / markContactAt /
 * log calls are NOT touched — only IncidentService calls are added alongside.
 *
 * @group integration
 */
final class HealthServiceIncidentTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_incidents');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_incidents");
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        // phpcs:enable WordPress.DB.PreparedSQL
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Creates an active site with a real Vault-encrypted dashboard private key.
     * Mirrors HealthServiceTest::makeActiveSite() exactly.
     */
    private function makeActiveSite(): int
    {
        $repo  = new SitesRepository();
        $vault = new Vault(DEFYN_VAULT_KEY);
        $priv  = base64_encode(random_bytes(64));
        $id    = $repo->insertPending(
            userId: 1,
            url: 'https://site.test',
            label: 'Site',
            ourPublicKey: base64_encode(random_bytes(32)),
            ourPrivateKeyEncrypted: $vault->encrypt($priv),
        );
        $repo->markActive($id, base64_encode(random_bytes(32)));
        return $id;
    }

    /** Creates a site with NO private key so ping() fast-fails on branch 1. */
    private function makeNoKeySite(): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => 1,
            'url'        => 'https://nokey-' . microtime(true) . '.test',
            'label'      => 'NoKey',
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Returns a SignedHttpClient backed by a mock that throws a TransportException. */
    private function failingClient(): SignedHttpClient
    {
        $mock = new MockHttpClient(function ($method, $url, $options) {
            throw new TransportException('connection refused');
        });
        return new SignedHttpClient($mock);
    }

    /** Returns a SignedHttpClient backed by a mock that returns HTTP 200. */
    private function successClient(): SignedHttpClient
    {
        $mock = new MockHttpClient(fn () => new MockResponse(
            json_encode(['ok' => true, 'server_time' => time()]),
            ['http_code' => 200],
        ));
        return new SignedHttpClient($mock);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * Core scenario: two failed pings open an incident; a success closes it.
     *
     * Uses the transport-error branch (branch 3 — $response['error'] !== '')
     * which is the most realistic failure scenario and avoids a live network.
     */
    public function test_two_failed_pings_open_incident_then_success_closes_it(): void
    {
        $siteId = $this->makeActiveSite();
        $repo   = new SitesRepository();

        $incidentService = new IncidentService();

        // First failure — counter = 1, below threshold, no incident yet.
        (new HealthService($this->failingClient(), null, null, $incidentService))->ping($siteId);
        $this->assertNull(
            (new IncidentsRepository())->findOpenForSite($siteId),
            'No incident expected after 1st failure (debounce threshold not met)'
        );

        // Second failure — counter = 2, meets threshold, incident MUST be opened.
        (new HealthService($this->failingClient(), null, null, $incidentService))->ping($siteId);
        $this->assertNotNull(
            (new IncidentsRepository())->findOpenForSite($siteId),
            'Incident must be open after 2nd consecutive failure'
        );

        // Mark site offline explicitly so the next ping takes the markRecovered
        // path (the transport failures already flipped status to 'offline', but
        // re-assert to be explicit about which success sub-path we exercise).
        $site = $repo->findById($siteId);
        $this->assertNotNull($site);
        $this->assertSame('offline', $site->status, 'Site must be offline before recovery ping');

        // Success ping — incident MUST be closed.
        (new HealthService($this->successClient(), null, null, $incidentService))->ping($siteId);
        $this->assertNull(
            (new IncidentsRepository())->findOpenForSite($siteId),
            'Incident must be closed after successful ping'
        );
    }

    /**
     * Single failure followed by a success must produce no incident and reset
     * the consecutive-failure counter (guardrail 4 of IncidentService).
     */
    public function test_single_failure_then_success_produces_no_incident(): void
    {
        $siteId = $this->makeActiveSite();
        $repo   = new SitesRepository();

        $incidentService = new IncidentService();

        // Single failure — counter = 1, no incident.
        (new HealthService($this->failingClient(), null, null, $incidentService))->ping($siteId);
        $this->assertNull((new IncidentsRepository())->findOpenForSite($siteId));

        // Site is now 'offline'; recovery ping uses markRecovered path.
        $site = $repo->findById($siteId);
        $this->assertNotNull($site);
        $this->assertSame('offline', $site->status);

        // Success — counter reset, no incident ever opened.
        (new HealthService($this->successClient(), null, null, $incidentService))->ping($siteId);
        $this->assertNull(
            (new IncidentsRepository())->findOpenForSite($siteId),
            'No incident expected after single-failure blip followed by recovery'
        );
        $fresh = $repo->findById($siteId);
        $this->assertNotNull($fresh);
        $this->assertSame(0, $fresh->consecutiveFailures, 'Counter must be reset to 0 (guardrail 4)');
    }

    /**
     * The missing-private-key fast-fail branch (branch 1) also feeds
     * IncidentService. Two pings on a no-key site must open an incident.
     */
    public function test_missing_private_key_branch_feeds_incident_service(): void
    {
        $siteId = $this->makeNoKeySite();

        $incidentService = new IncidentService();

        // First ping on no-key site — counter = 1, no incident.
        (new HealthService(new SignedHttpClient(), null, null, $incidentService))->ping($siteId);
        $this->assertNull(
            (new IncidentsRepository())->findOpenForSite($siteId),
            'No incident after 1st no-key ping (threshold not met)'
        );

        // Second ping — counter = 2, incident MUST open.
        (new HealthService(new SignedHttpClient(), null, null, $incidentService))->ping($siteId);
        $this->assertNotNull(
            (new IncidentsRepository())->findOpenForSite($siteId),
            'Incident must open after 2nd no-key ping'
        );
    }

    /**
     * Steady-state success (site already 'active', markContactAt path) calls
     * recordSuccess via the non-recovery sub-path and resets the failure counter.
     */
    public function test_steady_state_success_path_resets_failure_counter(): void
    {
        $siteId = $this->makeActiveSite();
        $repo   = new SitesRepository();

        $incidentService = new IncidentService();

        // One failure to prime the counter.
        (new HealthService($this->failingClient(), null, null, $incidentService))->ping($siteId);

        // The failure ping flipped status to 'offline'; flip it back to 'active'
        // so the next success ping takes the markContactAt branch (not markRecovered).
        $repo->markRecovered($siteId);
        $active = $repo->findById($siteId);
        $this->assertNotNull($active);
        $this->assertSame('active', $active->status);

        // Success via markContactAt branch — counter must be reset to 0.
        (new HealthService($this->successClient(), null, null, $incidentService))->ping($siteId);

        $fresh = $repo->findById($siteId);
        $this->assertNotNull($fresh);
        $this->assertSame(0, $fresh->consecutiveFailures, 'Counter must be reset by steady-state success path');
        $this->assertNull((new IncidentsRepository())->findOpenForSite($siteId));
    }
}
