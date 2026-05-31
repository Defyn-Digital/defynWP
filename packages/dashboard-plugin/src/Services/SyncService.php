<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Throwable;

/**
 * Pull a site's `/status` snapshot and persist it. Failures mark the site as
 * status=error with the transport / response message; successes write all
 * runtime info via SitesRepository::markSynced.
 *
 * Vault key must be configured (constant DEFYN_VAULT_KEY) — same precondition
 * as F5 site creation.
 *
 * Caller is the Task 15 Action Scheduler job (defyn_sync_site); the Task 16
 * REST controller schedules that job. sync() is intentionally `void` —
 * persistence is the side effect; callers read state back through
 * SitesRepository.
 *
 * TODO (later phase): also write an activity log row (`site.synced` /
 * `site.sync_failed`). Activity log table is not yet defined; this service
 * establishes only the persistence behavior in F6.
 */
final class SyncService
{
    public function __construct(
        private readonly SignedHttpClient $httpClient = new SignedHttpClient(),
        private readonly ?SitesRepository $repo = null,
    ) {}

    public function sync(int $siteId): void
    {
        $repo = $this->repo ?? new SitesRepository();
        $site = $repo->findById($siteId);
        if ($site === null) {
            // Site deleted between scheduling and execution — no-op.
            return;
        }

        if ($site->ourPrivateKey === null || $site->ourPrivateKey === '') {
            $repo->markError($siteId, 'Site is missing its encrypted private key.');
            return;
        }

        $vault = new Vault(DEFYN_VAULT_KEY);
        try {
            $privateKey = $vault->decrypt($site->ourPrivateKey);
        } catch (Throwable $e) {
            // Broad catch covers libsodium RuntimeException and InvalidArgumentException;
            // we don't surface the underlying message — could leak key-shape details.
            $repo->markError($siteId, 'Failed to decrypt site keypair.');
            return;
        }

        $url           = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/status';
        $canonicalPath = '/defyn-connector/v1/status';

        $response = $this->httpClient->signedGet($url, $privateKey, $canonicalPath);

        if ($response['error'] !== '') {
            $repo->markError($siteId, $response['error']);
            return;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $repo->markError($siteId, 'Connector returned status ' . $response['status']);
            return;
        }

        $info = $response['body'];
        if (!isset($info['wp_version'], $info['php_version'])) {
            $repo->markError($siteId, 'Connector returned malformed /status payload.');
            return;
        }

        $repo->markSynced($siteId, $info);
    }
}
