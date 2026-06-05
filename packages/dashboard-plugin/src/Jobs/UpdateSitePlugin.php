<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;

/**
 * P2.2 — Action Scheduler handler for `defyn_update_site_plugin($siteId, $slug, $attempt)`.
 *
 * Decrypts the per-site Ed25519 dashboard private key and calls the connector's signed
 * /plugins/{slug}/update endpoint with a 120-second timeout (matches the longest
 * realistic WP core upgrade), then branches on the response.
 *
 * Spec: docs/superpowers/specs/2026-06-05-p2-2-plugin-updates-design.md §6.3
 *
 * THIS TASK (Task 10) implements only the SUCCESS PATH. Tasks 11 + 12 will
 * extend the same handle() method with 409-collision retry and non-409 error
 * branching. Until those land, any non-2xx response is recorded as a generic
 * "Connector returned HTTP %d" failure so the row never gets stuck in
 * `updating` if the connector misbehaves.
 */
final class UpdateSitePlugin
{
    public const HOOK = 'defyn_update_site_plugin';

    /**
     * Connector upgrades can legitimately run for up to ~90 s on Kinsta when
     * core/many-plugin-update cycles trigger DB migrations; 120 s gives margin
     * without exceeding Action Scheduler's per-action wall time.
     */
    public const TIMEOUT_SECONDS = 120;

    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly SitePluginsRepository $repo = new SitePluginsRepository(),
        private readonly SignedHttpClient $http = new SignedHttpClient(),
        private readonly ActivityLogger $log = new ActivityLogger(),
        private readonly ?Vault $vault = null,
    ) {
    }

    public function handle(int $siteId, string $slug, int $attempt = 0): void
    {
        $site = $this->sites->findById($siteId);
        if ($site === null) {
            return;
        }

        $row = $this->repo->findRowForSiteAndSlug($siteId, $slug);
        if ($row === null) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');

        $this->repo->markUpdating($siteId, $slug, $now);
        // ActivityLogger::log(?int $userId, ?int $siteId, string $eventType, ?array $details).
        // Background job — null user. siteId is second.
        $this->log->log(null, $siteId, 'plugin_update.started', [
            'slug'            => $slug,
            'current_version' => $row['version'] ?? null,
            'target_version'  => $row['update_version'] ?? null,
        ]);

        $vault      = $this->vault ?? new Vault(DEFYN_VAULT_KEY);
        $privateKey = $vault->decrypt((string) $site->ourPrivateKey);

        $url           = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/plugins/' . $slug . '/update';
        $canonicalPath = '/defyn-connector/v1/plugins/' . $slug . '/update';

        $response = $this->http->signedPostJson(
            $url,
            [],
            $privateKey,
            $canonicalPath,
            timeoutSeconds: self::TIMEOUT_SECONDS,
        );

        if ($response['status'] === 200 && !empty($response['body']['success'])) {
            $previousVersion = (string) ($response['body']['previous_version'] ?? $row['version'] ?? '');
            $newVersion      = (string) ($response['body']['new_version'] ?? $row['version'] ?? '');

            $this->repo->markUpdateSucceeded($siteId, $slug, $newVersion, $now);
            $this->log->log(null, $siteId, 'plugin_update.succeeded', [
                'slug'             => $slug,
                'previous_version' => $previousVersion,
                'new_version'      => $newVersion,
            ]);
            return;
        }

        // 409 + plugins.update_in_progress → exponential backoff retry (up to 5).
        // Spec § 6.3: 60s, 120s, 240s, 480s, 960s — ~32 min total budget.
        if (
            $response['status'] === 409
            && ($response['body']['error']['code'] ?? '') === 'plugins.update_in_progress'
        ) {
            if ($attempt >= 5) {
                $this->repo->markUpdateFailed(
                    $siteId,
                    $slug,
                    'Site is busy after 5 retries.',
                    $now,
                );
                $this->log->log(null, $siteId, 'plugin_update.failed', [
                    'slug'              => $slug,
                    'error_message'     => 'Site is busy after 5 retries.',
                    'attempted_version' => $row['update_version'] ?? null,
                ]);
                return;
            }

            $delay   = 60 * (2 ** $attempt); // 60, 120, 240, 480, 960
            $nextRun = time() + $delay;
            \as_schedule_single_action($nextRun, self::HOOK, [$siteId, $slug, $attempt + 1]);
            $this->log->log(null, $siteId, 'plugin_update.retry', [
                'slug'        => $slug,
                'attempt'     => $attempt,
                'next_run_at' => gmdate('Y-m-d H:i:s', $nextRun),
            ]);
            return;
        }

        // Generic failure fallback for Task 10/11. Task 12 (non-409 branched
        // error messages) will replace this with proper handling.
        $errorMessage = sprintf('Connector returned HTTP %d.', $response['status']);
        $this->repo->markUpdateFailed($siteId, $slug, $errorMessage, $now);
        $this->log->log(null, $siteId, 'plugin_update.failed', [
            'slug'              => $slug,
            'error_message'     => $errorMessage,
            'attempted_version' => $row['update_version'] ?? null,
        ]);
    }
}
