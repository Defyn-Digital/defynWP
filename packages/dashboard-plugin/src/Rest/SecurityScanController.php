<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\SecurityScan;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\VulnFeedService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P4.1 — POST /defyn/v1/sites/{id}/security/scan
 *
 * Schedules an on-demand `defyn_security_scan` Action Scheduler job for the
 * given site and calls VulnFeedService::refreshIfStale() best-effort (no-op
 * without an API key / when the feed is fresh). Returns 202 immediately —
 * the actual scan runs async on the next AS tick.
 *
 * Ownership-gated: 404 sites.not_found when the site is not owned by the
 * authenticated user (anti-enumeration, same shape as SitesVulnerabilitiesController).
 *
 * Rate-limited at the permission_callback layer by RateLimit::securityScan —
 * 6 requests / hour / (user, site).
 */
final class SecurityScanController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        // Best-effort feed freshness (no-op without a key / when fresh). Never throws.
        (new VulnFeedService())->refreshIfStale();

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), SecurityScan::HOOK, [$siteId], 'defyn');
        }

        return new WP_REST_Response(['scheduled' => true, 'site_id' => $siteId], 202);
    }
}
