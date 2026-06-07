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

        // 409 + core.no_update_available -> success-by-other-means.
        if (
            $response['status'] === 409
            && ($response['body']['error']['code'] ?? '') === 'core.no_update_available'
        ) {
            $this->sites->markCoreUpdateSucceeded($siteId, $wpVersionBeforeAttempt, $now);
            $this->log->log(null, $siteId, 'core_update.succeeded_no_change', [
                'current_version' => $wpVersionBeforeAttempt,
            ]);
            return;
        }

        // 409 + core.major_update_blocked -> immediate fail, NO retry.
        if (
            $response['status'] === 409
            && ($response['body']['error']['code'] ?? '') === 'core.major_update_blocked'
        ) {
            $errorMessage = (string) ($response['body']['error']['message'] ?? 'Major-version update blocked.');
            $this->sites->markCoreUpdateFailed($siteId, $errorMessage, $now);
            $this->log->log(null, $siteId, 'core_update.blocked_major', [
                'error_message' => $errorMessage,
            ]);
            return;
        }

        // 409 + connector.upgrade_in_progress -> exponential backoff retry (max 5).
        if (
            $response['status'] === 409
            && ($response['body']['error']['code'] ?? '') === 'connector.upgrade_in_progress'
        ) {
            if ($attempt >= 5) {
                $this->sites->markCoreUpdateFailed(
                    $siteId,
                    'Site is busy after 5 retries.',
                    $now,
                );
                $this->log->log(null, $siteId, 'core_update.failed', [
                    'error_code'        => 'retry_exhausted',
                    'error_message'     => 'Site is busy after 5 retries.',
                    'attempted_version' => $targetVersion,
                ]);
                return;
            }

            $delay   = 60 * (2 ** $attempt);
            $nextRun = time() + $delay;
            \as_schedule_single_action($nextRun, self::HOOK, [$siteId, $attempt + 1]);
            $this->log->log(null, $siteId, 'core_update.retry', [
                'attempt'     => $attempt,
                'next_run_at' => gmdate('Y-m-d H:i:s', $nextRun),
            ]);
            return;
        }

        // All other failures (non-2xx, parse failure, transport error). NO retry.
        $errorMessage = $response['body']['error']['message']
            ?? ($response['error'] !== '' ? $response['error'] : sprintf('Connector returned HTTP %d.', $response['status']));

        $this->sites->markCoreUpdateFailed($siteId, $errorMessage, $now);
        $this->log->log(null, $siteId, 'core_update.failed', [
            'error_code'        => 'connector_failure',
            'error_message'     => $errorMessage,
            'attempted_version' => $targetVersion,
        ]);
    }
}
