<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Throwable;

/**
 * Signed liveness probe against the connector's /heartbeat (spec § 5.1).
 *
 * Counterpart to SyncService: cheap, frequent ping that records liveness
 * without pulling the full /status payload.
 *
 * Success: advances last_contact_at via SitesRepository::markContactAt; if
 * the site was previously 'offline', flips it back to 'active' (recovery)
 * via markRecovered (clears last_error too — no ghost message in the SPA).
 *
 * Failure (transport, non-2xx, or decrypt error): flips the site to
 * 'offline' via markOffline and records the message.
 *
 * Caller is the Task 15 Action Scheduler job (defyn_health_ping); the
 * Task 16 REST controller schedules that job. ping() is intentionally
 * `void` — persistence is the side effect; callers read state back through
 * SitesRepository.
 *
 * F9: every persistence-touching branch also writes an ActivityLogger row.
 * Success paths emit `site.health_ok` (steady-state ping) or `site.recovered`
 * (transition from offline → active); every failure branch emits
 * `site.health_failed` with the same message that was persisted to last_error.
 * IP address is left null — this service runs from Action Scheduler.
 */
final class HealthService
{
    public function __construct(
        private readonly SignedHttpClient $httpClient = new SignedHttpClient(),
        private readonly ?SitesRepository $repo = null,
        private readonly ?ActivityLogger $logger = null,
        private readonly ?IncidentService $incidents = null,
    ) {}

    public function ping(int $siteId): void
    {
        $repo   = $this->repo ?? new SitesRepository();
        $logger = $this->logger ?? new ActivityLogger();

        $site = $repo->findById($siteId);
        if ($site === null) {
            // Site deleted between scheduling and execution — no-op.
            return;
        }

        if ($site->ourPrivateKey === null || $site->ourPrivateKey === '') {
            $message = 'Site is missing its encrypted private key.';
            $repo->markOffline($siteId, $message);
            $logger->log($site->userId, $siteId, 'site.health_failed', ['error' => $message]);
            ($this->incidents ?? new IncidentService())->recordFailure($site, $message);
            $repo->recordResponseTime($siteId, null);
            return;
        }

        $vault = new Vault(DEFYN_VAULT_KEY);
        try {
            $privateKey = $vault->decrypt($site->ourPrivateKey);
        } catch (Throwable $e) {
            // Mirror SyncService: broad catch covers libsodium runtime exceptions
            // without leaking key-shape details into the persisted message.
            $message = 'Failed to decrypt site keypair.';
            $repo->markOffline($siteId, $message);
            $logger->log($site->userId, $siteId, 'site.health_failed', ['error' => $message]);
            ($this->incidents ?? new IncidentService())->recordFailure($site, $message);
            $repo->recordResponseTime($siteId, null);
            return;
        }

        $url           = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/heartbeat';
        $canonicalPath = '/defyn-connector/v1/heartbeat';

        $startedAt = microtime(true);
        $response  = $this->httpClient->signedGet($url, $privateKey, $canonicalPath);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response['error'] !== '') {
            $repo->markOffline($siteId, $response['error']);
            $logger->log($site->userId, $siteId, 'site.health_failed', ['error' => $response['error']]);
            ($this->incidents ?? new IncidentService())->recordFailure($site, $response['error']);
            $repo->recordResponseTime($siteId, null);
            return;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = 'Connector returned status ' . $response['status'];
            $repo->markOffline($siteId, $message);
            $logger->log($site->userId, $siteId, 'site.health_failed', ['error' => $message]);
            ($this->incidents ?? new IncidentService())->recordFailure($site, $message);
            $repo->recordResponseTime($siteId, null);
            return;
        }

        if ($site->status === 'offline') {
            $repo->markRecovered($siteId);
            $logger->log($site->userId, $siteId, 'site.recovered');
            ($this->incidents ?? new IncidentService())->recordSuccess($site);
            $repo->recordResponseTime($siteId, $elapsedMs);
        } else {
            $repo->markContactAt($siteId);
            $logger->log($site->userId, $siteId, 'site.health_ok');
            ($this->incidents ?? new IncidentService())->recordSuccess($site);
            $repo->recordResponseTime($siteId, $elapsedMs);
        }
    }
}
