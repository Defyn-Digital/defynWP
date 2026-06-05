<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\UpdateSitePlugin;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.2 — POST /defyn/v1/sites/{id}/plugins/{slug}/update.
 *
 * The dashboard's per-plugin update trigger. Auth + the 6/hour per
 * (user, site, slug) rate limit run in the permission_callback layer
 * (RateLimit::pluginsUpdate, which chains RequireAuth::check). This handler
 * runs only after both pass, and is responsible for:
 *
 *   1. Owner check via SitesRepository::findByIdForUser → 404 sites.not_found.
 *   2. Inventory check via SitePluginsRepository::findRowForSiteAndSlug →
 *      404 plugins.not_found_in_inventory.
 *   3. "Update actually available" guard → 409 plugins.no_update_available.
 *      Prevents an SPA that lagged behind a successful update from re-queueing
 *      a no-op.
 *   4. "Already in flight" guard → 409 plugins.update_already_in_progress.
 *      Bounces a second click while the row is queued/updating; the spec
 *      treats this as the user's intent to wait, not a retry signal.
 *   5. Optimistic `update_state='queued'` write — the SPA polls this row to
 *      flip the row UI into the spinner state without waiting for the AS
 *      tick that runs UpdateSitePlugin.
 *   6. `plugin_update.requested` activity log entry with the operator's
 *      userId + the target/current versions snapshotted at queue time.
 *   7. `as_schedule_single_action` of UpdateSitePlugin::HOOK with attempt=0.
 *      Group is left to AS's default — UpdateSitePlugin's own retries don't
 *      pass a group either, so the success/retry handlers stay symmetric.
 *
 * Returns 202 (Accepted) — the connector round-trip + DB write happens
 * asynchronously on the next AS tick.
 *
 * Spec: docs/superpowers/specs/2026-06-05-p2-2-plugin-updates-design.md §7.1, §7.3
 */
final class SitesPluginsUpdateController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        $slug   = (string) $request->get_param('slug');

        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $repo = new SitePluginsRepository();
        $row  = $repo->findRowForSiteAndSlug($siteId, $slug);
        if ($row === null) {
            return ErrorResponse::create(
                404,
                'plugins.not_found_in_inventory',
                sprintf('Plugin "%s" is not in this site\'s inventory.', $slug),
            );
        }

        if ((int) $row['update_available'] === 0) {
            return ErrorResponse::create(
                409,
                'plugins.no_update_available',
                sprintf('No update available for "%s".', $slug),
            );
        }

        if (in_array($row['update_state'], ['queued', 'updating'], true)) {
            return ErrorResponse::create(
                409,
                'plugins.update_already_in_progress',
                sprintf('Update for "%s" is already in progress.', $slug),
            );
        }

        $now = gmdate('Y-m-d H:i:s');
        $repo->markUpdateRequested($siteId, $slug, $now);

        // ActivityLogger::log(?int $userId, ?int $siteId, string $eventType, ?array $details).
        // Operator-triggered, so user_id = authed user; site_id second. Version
        // snapshot is taken from the row BEFORE markUpdateRequested mutates it
        // (markUpdateRequested only touches update_state / timestamps, but
        // capturing here keeps the log shape independent of repo internals).
        (new ActivityLogger())->log($userId, $siteId, 'plugin_update.requested', [
            'slug'            => $slug,
            'current_version' => $row['version'] ?? null,
            'target_version'  => $row['update_version'] ?? null,
        ]);

        \as_schedule_single_action(time(), UpdateSitePlugin::HOOK, [$siteId, $slug, 0]);

        return new WP_REST_Response([
            'scheduled' => true,
            'site_id'   => $siteId,
            'slug'      => $slug,
        ], 202);
    }
}
