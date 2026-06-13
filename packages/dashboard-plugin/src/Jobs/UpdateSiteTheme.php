<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\ThemesRepository;

/**
 * P2.3 — Action Scheduler handler for `defyn_update_site_theme($siteId, $slug, $attempt)`.
 *
 * Decrypts the per-site Ed25519 dashboard private key and calls the connector's signed
 * /themes/{slug}/update endpoint with a 120-second timeout (matches the longest realistic
 * theme upgrade window including DB migrations on Block Theme installs), then branches on
 * the response.
 *
 * Activity log triplet (spec § 8.2): theme_update.requested → theme_update.started →
 * theme_update.succeeded|failed. This job emits .started + .succeeded|.failed (the
 * .requested event is written by SitesThemesUpdateController at queue time).
 *
 * Spec: docs/superpowers/specs/2026-06-06-p2-3-themes-design.md §4.4
 */
final class UpdateSiteTheme
{
    public const HOOK = 'defyn_update_site_theme';

    /** Connector upgrades can legitimately run for up to ~90 s on Kinsta. */
    public const TIMEOUT_SECONDS = 120;

    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly ThemesRepository $repo = new ThemesRepository(),
        private readonly SignedHttpClient $http = new SignedHttpClient(),
        private readonly ActivityLogger $log = new ActivityLogger(),
        private readonly ?Vault $vault = null,
        private readonly BulkJobsRepository $bulkJobs = new BulkJobsRepository(),
    ) {
    }

    public function handle(int $siteId, string $slug, int $attempt = 0, int $jobItemId = 0): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $site = $this->sites->findById($siteId);
        if ($site === null) {
            // Terminal — a bulk-job item pointing at a deleted site can never
            // succeed; without the mark it would hang in `queued` forever.
            if ($jobItemId > 0) {
                $this->bulkJobs->markItemFailed($jobItemId, $now, 'Site no longer exists.');
            }
            return;
        }

        $row = $this->repo->findRowForSiteAndSlug($siteId, $slug);
        if ($row === null) {
            if ($jobItemId > 0) {
                $this->bulkJobs->markItemFailed($jobItemId, $now, 'Theme row no longer exists.');
            }
            return;
        }

        // P2.9 — queued → started at handle entry. The repository UPDATE is
        // guarded queued-only, so 409-retry re-entries (already started) no-op.
        if ($jobItemId > 0) {
            $this->bulkJobs->markItemStarted($jobItemId, $now);
        }

        $this->repo->markUpdating($siteId, $slug, $now);
        // theme_update.started — second of the requested→started→succeeded|failed triplet.
        $this->log->log(null, $siteId, 'theme_update.started', [
            'slug'            => $slug,
            'current_version' => $row['version'] ?? null,
            'target_version'  => $row['update_version'] ?? null,
            'attempt'         => $attempt,
        ]);

        $vault      = $this->vault ?? new Vault(DEFYN_VAULT_KEY);
        $privateKey = $vault->decrypt((string) $site->ourPrivateKey);

        $url           = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/themes/' . $slug . '/update';
        $canonicalPath = '/defyn-connector/v1/themes/' . $slug . '/update';

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
            $this->log->log(null, $siteId, 'theme_update.succeeded', [
                'slug'             => $slug,
                'previous_version' => $previousVersion,
                'new_version'      => $newVersion,
            ]);
            if ($jobItemId > 0) {
                $this->bulkJobs->markItemSucceeded($jobItemId, $now);
            }
            return;
        }

        // 409 + connector.upgrade_in_progress → exponential backoff retry (up to 5).
        // 60s, 120s, 240s, 480s, 960s — ~32 min total budget.
        if (
            $response['status'] === 409
            && ($response['body']['error']['code'] ?? '') === 'connector.upgrade_in_progress'
        ) {
            if ($attempt >= 5) {
                $this->repo->markUpdateFailed(
                    $siteId,
                    $slug,
                    'Site is busy after 5 retries.',
                    $now,
                );
                $this->log->log(null, $siteId, 'theme_update.failed', [
                    'slug'              => $slug,
                    'error_message'     => 'Site is busy after 5 retries.',
                    'attempted_version' => $row['update_version'] ?? null,
                ]);
                if ($jobItemId > 0) {
                    $this->bulkJobs->markItemFailed($jobItemId, $now, 'Site is busy after 5 retries.');
                }
                return;
            }

            $delay   = 60 * (2 ** $attempt);
            $nextRun = time() + $delay;
            // P2.9 — propagate the item id so the retry attempt keeps marking
            // lifecycle on the SAME item. No terminal mark — stays `started`.
            \as_schedule_single_action($nextRun, self::HOOK, [$siteId, $slug, $attempt + 1, $jobItemId]);
            $this->log->log(null, $siteId, 'theme_update.retry', [
                'slug'        => $slug,
                'attempt'     => $attempt,
                'next_run_at' => gmdate('Y-m-d H:i:s', $nextRun),
            ]);
            return;
        }

        // 409 + themes.no_update_available → success-by-other-means.
        // The connector says the on-disk version is current (someone else upgraded
        // it, the operator manually upgraded via wp-admin, an auto-update fired,
        // etc). The row's `version` column read BEFORE this attempt is the right
        // post-attempt value — NOT the connector's update_version which doesn't
        // exist on this path.
        if (
            $response['status'] === 409
            && ($response['body']['error']['code'] ?? '') === 'themes.no_update_available'
        ) {
            $rowVersionBeforeAttempt = (string) ($row['version'] ?? '');
            $this->repo->markUpdateSucceeded($siteId, $slug, $rowVersionBeforeAttempt, $now);
            $this->log->log(null, $siteId, 'theme_update.succeeded_no_change', [
                'slug'            => $slug,
                'current_version' => $rowVersionBeforeAttempt,
            ]);
            // 409-as-success — the item's goal was achieved by other means.
            if ($jobItemId > 0) {
                $this->bulkJobs->markItemSucceeded($jobItemId, $now);
            }
            return;
        }

        // Failure-message extraction order (spec § 4.4):
        //   1. Connector envelope `body.error.message` — preferred, this is the
        //      human-readable upgrade failure (e.g. "Could not copy file ...").
        //   2. SignedHttpClient transport error — populated when status === 0
        //      because the wire request never completed (DNS, refused, TLS, timeout).
        //   3. Generic "HTTP %d" fallback — only when both upstream sources are empty.
        $errorMessage = $response['body']['error']['message']
            ?? ($response['error'] !== '' ? $response['error'] : sprintf('Connector returned HTTP %d.', $response['status']));

        $this->repo->markUpdateFailed($siteId, $slug, $errorMessage, $now);
        $this->log->log(null, $siteId, 'theme_update.failed', [
            'slug'              => $slug,
            'error_message'     => $errorMessage,
            'attempted_version' => $row['update_version'] ?? null,
        ]);
        if ($jobItemId > 0) {
            $this->bulkJobs->markItemFailed($jobItemId, $now, $errorMessage);
        }
    }
}
