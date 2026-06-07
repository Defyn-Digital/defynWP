<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\RefreshSiteCore;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.4 — POST /defyn/v1/sites/{id}/core/refresh.
 *
 * Schedules `defyn_refresh_site_core` (Action Scheduler, handled by
 * Jobs\RefreshSiteCore) and writes `core_inventory.refresh_requested`
 * to the activity log. Returns 202 — the connector round-trip + delta sync
 * runs async on the next AS tick.
 *
 * Mirrors SitesPluginsRefreshController and SitesThemesRefreshController's
 * user-scoped ownership gate. Rate-limited at the permission_callback layer
 * by RateLimit::sitesCoreRefresh — 6 requests / hour / (user, site), separate
 * budget from pluginsRefresh and sitesThemesRefresh.
 */
final class SitesCoreRefreshController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), RefreshSiteCore::HOOK, [$siteId], 'defyn');
        }

        (new ActivityLogger())->log($userId, $siteId, 'core_inventory.refresh_requested', null);

        return new WP_REST_Response(['scheduled' => true, 'site_id' => $siteId], 202);
    }
}
