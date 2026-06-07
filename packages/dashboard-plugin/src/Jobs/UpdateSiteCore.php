<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;

/**
 * P2.4 — Action Scheduler handler for `defyn_update_site_core($siteId, $attempt)`.
 *
 * 300-second timeout (vs P2.3 themes' 120s) — core upgrades involve WP DB migrations.
 *
 * Activity log triplet (spec § 8.2): core_update.requested -> core_update.started ->
 * core_update.succeeded|failed.
 *
 * Spec: docs/superpowers/specs/2026-06-07-p2-4-core-updates-design.md §4.3
 */
final class UpdateSiteCore
{
    public const HOOK = 'defyn_update_site_core';
    public const TIMEOUT_SECONDS = 300;

    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly SignedHttpClient $http = new SignedHttpClient(),
        private readonly ActivityLogger $log = new ActivityLogger(),
        private readonly ?Vault $vault = null,
    ) {
    }

    public function handle(int $siteId, int $attempt = 0): void
    {
        $site = $this->sites->findById($siteId);
        if ($site === null) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');

        $wpVersionBeforeAttempt = (string) ($site->wpVersion ?? '');
        $targetVersion = (string) ($site->coreUpdateVersion ?? '');

        $this->sites->markCoreUpdating($siteId, $now);

        $this->log->log(null, $siteId, 'core_update.started', [
            'previous_version' => $wpVersionBeforeAttempt,
            'target_version'   => $targetVersion,
            'attempt'          => $attempt,
        ]);

        $vault      = $this->vault ?? new Vault(DEFYN_VAULT_KEY);
        $privateKey = $vault->decrypt((string) $site->ourPrivateKey);

        $url           = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/core/update';
        $canonicalPath = '/defyn-connector/v1/core/update';

        $response = $this->http->signedPostJson(
            $url,
            [],
            $privateKey,
            $canonicalPath,
            timeoutSeconds: self::TIMEOUT_SECONDS,
        );

        if ($response['status'] === 200 && !empty($response['body']['success'])) {
            $previousVersion = (string) ($response['body']['previous_version'] ?? $wpVersionBeforeAttempt);
            $newVersion      = (string) ($response['body']['new_version'] ?? $targetVersion);

            $this->sites->markCoreUpdateSucceeded($siteId, $newVersion, $now);
            $this->log->log(null, $siteId, 'core_update.succeeded', [
                'previous_version' => $previousVersion,
                'new_version'      => $newVersion,
            ]);
            return;
        }

        // Task 14 layers on the four remaining branches.
    }
}
