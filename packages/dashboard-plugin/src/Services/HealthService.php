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
 * TODO (later phase): activity-log rows for `site.health_ok` /
 * `site.health_fail`. The activity_log table is not yet defined; this
 * service establishes only the persistence behavior in F6.
 */
final class HealthService
{
    public function __construct(
        private readonly SignedHttpClient $httpClient = new SignedHttpClient(),
        private readonly ?SitesRepository $repo = null,
    ) {}

    public function ping(int $siteId): void
    {
        $repo = $this->repo ?? new SitesRepository();
        $site = $repo->findById($siteId);
        if ($site === null) {
            // Site deleted between scheduling and execution — no-op.
            return;
        }

        if ($site->ourPrivateKey === null || $site->ourPrivateKey === '') {
            $repo->markOffline($siteId, 'Site is missing its encrypted private key.');
            return;
        }

        $vault = new Vault(DEFYN_VAULT_KEY);
        try {
            $privateKey = $vault->decrypt($site->ourPrivateKey);
        } catch (Throwable $e) {
            // Mirror SyncService: broad catch covers libsodium runtime exceptions
            // without leaking key-shape details into the persisted message.
            $repo->markOffline($siteId, 'Failed to decrypt site keypair.');
            return;
        }

        $url           = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/heartbeat';
        $canonicalPath = '/defyn-connector/v1/heartbeat';

        $response = $this->httpClient->signedGet($url, $privateKey, $canonicalPath);

        if ($response['error'] !== '') {
            $repo->markOffline($siteId, $response['error']);
            return;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $repo->markOffline($siteId, 'Connector returned status ' . $response['status']);
            return;
        }

        if ($site->status === 'offline') {
            $repo->markRecovered($siteId);
        } else {
            $repo->markContactAt($siteId);
        }
    }
}
