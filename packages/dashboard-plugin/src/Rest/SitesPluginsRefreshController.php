<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.1 — POST /defyn/v1/sites/{id}/plugins/refresh.
 *
 * Schedules `defyn_refresh_site_plugins` (Action Scheduler, handled by
 * Jobs\RefreshSitePlugins from Task 8) and writes `plugin_inventory.refresh_requested`
 * to the activity log. Returns 202 — the actual connector round-trip + delta sync
 * runs async on the next AS tick.
 *
 * Mirrors SitesSyncController's user-scoped ownership gate: 404 sites.not_found if
 * the site isn't owned by the authenticated user (anti-enumeration, same shape as
 * SitesShowController / SitesPluginsListController).
 *
 * Rate-limited at the permission_callback layer by RateLimit::pluginsRefresh —
 * 6 requests / minute / (user, site).
 */
final class SitesPluginsRefreshController
{
    public const HOOK = 'defyn_refresh_site_plugins';

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), self::HOOK, [$siteId], 'defyn');
        }

        // ActivityLogger::log(?int $userId, ?int $siteId, string $eventType, ?array $details).
        // Operator-triggered, so user_id is the authenticated user and site_id is the
        // target site. The arg order matters — Task 7 had a flip bug; the test for this
        // controller asserts user_id + site_id columns explicitly to lock it in.
        (new ActivityLogger())->log($userId, $siteId, 'plugin_inventory.refresh_requested', null);

        return new WP_REST_Response(['scheduled' => true, 'site_id' => $siteId], 202);
    }
}
