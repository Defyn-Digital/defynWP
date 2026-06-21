<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\LinkScan;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P7.1 — POST /defyn/v1/sites/{id}/links/scan
 * Enqueues an on-demand broken-link crawl for the given site and returns 202
 * immediately — the scan runs async via the LinkScan AS job.
 * Ownership-gated: 404 sites.not_found when the site is not owned by the caller.
 * Envelope: { data: { scheduled: true }, error: null }.
 */
final class SitesLinksScanController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(LinkScan::HOOK, [$siteId], 'defyn');
        }

        return new WP_REST_Response(['data' => ['scheduled' => true], 'error' => null], 202);
    }
}
