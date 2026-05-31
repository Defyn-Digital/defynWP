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
 * F9: every code path that touches persistence also writes an ActivityLogger
 * row — `site.synced` on the happy path, `site.sync_failed` on every failure
 * branch. IP address is left null since this service is invoked from an
 * Action Scheduler job that has no request context.
 */
final class SyncService
{
    public function __construct(
        private readonly SignedHttpClient $httpClient = new SignedHttpClient(),
        private readonly ?SitesRepository $repo = null,
        private readonly ?ActivityLogger $logger = null,
    ) {}

    public function sync(int $siteId): void
    {
        $repo   = $this->repo ?? new SitesRepository();
        $logger = $this->logger ?? new ActivityLogger();

        $site = $repo->findById($siteId);
        if ($site === null) {
            // Site deleted between scheduling and execution — no-op. No log
            // either: the user_id is unknown and the row no longer exists.
            return;
        }

        if ($site->ourPrivateKey === null || $site->ourPrivateKey === '') {
            $message = 'Site is missing its encrypted private key.';
            $repo->markError($siteId, $message);
            $logger->log($site->userId, $siteId, 'site.sync_failed', ['error' => $message]);
            return;
        }

        $vault = new Vault(DEFYN_VAULT_KEY);
        try {
            $privateKey = $vault->decrypt($site->ourPrivateKey);
        } catch (Throwable $e) {
            // Broad catch covers libsodium RuntimeException and InvalidArgumentException;
            // we don't surface the underlying message — could leak key-shape details.
            $message = 'Failed to decrypt site keypair.';
            $repo->markError($siteId, $message);
            $logger->log($site->userId, $siteId, 'site.sync_failed', ['error' => $message]);
            return;
        }

        $url           = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/status';
        $canonicalPath = '/defyn-connector/v1/status';

        $response = $this->httpClient->signedGet($url, $privateKey, $canonicalPath);

        if ($response['error'] !== '') {
            $repo->markError($siteId, $response['error']);
            $logger->log($site->userId, $siteId, 'site.sync_failed', ['error' => $response['error']]);
            return;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = 'Connector returned status ' . $response['status'];
            $repo->markError($siteId, $message);
            $logger->log($site->userId, $siteId, 'site.sync_failed', ['error' => $message]);
            return;
        }

        $info = $response['body'];
        if (!isset($info['wp_version'], $info['php_version'])) {
            $message = 'Connector returned malformed /status payload.';
            $repo->markError($siteId, $message);
            $logger->log($site->userId, $siteId, 'site.sync_failed', ['error' => $message]);
            return;
        }

        $repo->markSynced($siteId, $info);
        $logger->log($site->userId, $siteId, 'site.synced', ['wp_version' => $info['wp_version'] ?? null]);
    }
}
