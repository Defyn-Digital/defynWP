<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.4.1 — POST /defyn/v1/sites/{id}/core/allow-major.
 *
 * Toggles the per-site core_allow_major flag. When on, major-version
 * WordPress core upgrades become eligible (the dashboard's preflight #4
 * and the connector's CoreUpgraderService respect the flag).
 *
 * Spec: docs/superpowers/specs/2026-06-07-p2-4-1-major-core-updates-design.md § 4.7
 */
final class SitesCoreAllowMajorController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $body = $request->get_json_params() ?: [];
        if (!array_key_exists('allow', $body) || !is_bool($body['allow'])) {
            return ErrorResponse::create(
                400,
                'core.invalid_payload',
                'Request body must include an "allow" field with a boolean value.',
            );
        }
        $allow = (bool) $body['allow'];

        (new SitesRepository())->setCoreAllowMajor($siteId, $allow);

        (new ActivityLogger())->log($userId, $siteId, 'core_allow_major.toggled', [
            'enabled' => $allow,
        ]);

        return new WP_REST_Response([
            'site_id'          => $siteId,
            'core_allow_major' => $allow,
        ], 200);
    }
}
