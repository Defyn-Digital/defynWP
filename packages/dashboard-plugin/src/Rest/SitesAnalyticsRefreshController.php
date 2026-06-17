<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\AnalyticsSync;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/** P6.2 — POST /sites/{id}/analytics/refresh — enqueue an on-demand GA4 sync (202). */
final class SitesAnalyticsRefreshController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(AnalyticsSync::HOOK, [$siteId], 'defyn');
        }
        return new WP_REST_Response(['data' => ['scheduled' => true], 'error' => null], 202);
    }
}
