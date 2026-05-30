<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\HealthPing;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /defyn/v1/sites/{id}/ping — SPA-triggered health ping.
 *
 * User-scoped: 404 if the site is not owned by the authenticated user (mirrors
 * SitesShowController). On success, schedules HealthPing::HOOK via Action Scheduler
 * for the next AS tick and returns 202 Accepted.
 */
final class SitesPingController
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
            as_schedule_single_action(time(), HealthPing::HOOK, [$siteId], 'defyn');
        }

        return new WP_REST_Response(['site_id' => $siteId, 'scheduled' => true], 202);
    }
}
