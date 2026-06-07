<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Throwable;

/**
 * P2.4 — Action Scheduler hook handler for `defyn_refresh_site_core`.
 *
 * Scheduled by SitesCoreRefreshController on operator click or by
 * SyncSite extension on the recurring background tick. Forces a fresh
 * /core/refresh against the connector then runs the site sync (which now
 * writes the core sub-object via SitesRepository::markSynced). Failures
 * log `site.core_refresh_failed`.
 *
 * Direct mirror of RefreshSiteThemes (P2.3 Task 13).
 */
final class RefreshSiteCore
{
    public const HOOK = 'defyn_refresh_site_core';

    public function __construct(
        private readonly SitesRepository $repo = new SitesRepository(),
        private readonly SignedHttpClient $httpClient = new SignedHttpClient(),
        private readonly ActivityLogger $log = new ActivityLogger(),
    ) {
    }

    public function handle(int $siteId): void
    {
        $site = $this->repo->findById($siteId);
        if ($site === null || $site->status === 'pending') {
            return;
        }

        if ($site->ourPrivateKey === null || $site->ourPrivateKey === '') {
            $this->logFailed($siteId, 'Site is missing its encrypted private key.');
            return;
        }

        $vault = new Vault(DEFYN_VAULT_KEY);
        try {
            $privateKey = $vault->decrypt($site->ourPrivateKey);
        } catch (Throwable) {
            $this->logFailed($siteId, 'Failed to decrypt site keypair.');
            return;
        }

        $url           = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/core/refresh';
        $canonicalPath = '/defyn-connector/v1/core/refresh';

        $response = $this->httpClient->signedPostJson($url, [], $privateKey, $canonicalPath);

        if ($response['error'] !== '') {
            $this->logFailed($siteId, $response['error']);
            return;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->logFailed($siteId, 'Connector returned status ' . $response['status']);
            return;
        }

        $coreSubObject = $response['body'];
        $statusShim = [
            'wp_version'     => $site->wpVersion ?? '',
            'php_version'    => $site->phpVersion ?? '',
            'plugin_counts'  => $site->pluginCounts ?? ['installed' => 0, 'active' => 0],
            'theme_counts'   => $site->themeCounts ?? ['installed' => 0, 'active' => 0],
            'ssl_status'     => $site->sslStatus ?? '',
            'ssl_expires_at' => $site->sslExpiresAt,
            'core'           => [
                'update_available'       => (bool) ($coreSubObject['update_available'] ?? false),
                'update_version'         => $coreSubObject['update_version'] ?? null,
                'is_minor_update'        => (bool) ($coreSubObject['is_minor_update'] ?? false),
                'is_auto_update_enabled' => (bool) ($coreSubObject['is_auto_update_enabled'] ?? true),
            ],
        ];

        $this->repo->markSynced($siteId, $statusShim);

        $this->log->log(null, $siteId, 'core_inventory.refreshed', [
            'update_available' => (bool) ($coreSubObject['update_available'] ?? false),
            'update_version'   => $coreSubObject['update_version'] ?? null,
            'source'           => 'refresh',
        ]);
    }

    private function logFailed(int $siteId, string $error): void
    {
        $this->log->log(null, $siteId, 'site.core_refresh_failed', [
            'error'  => substr($error, 0, 1000),
            'source' => 'refresh',
        ]);
    }
}
