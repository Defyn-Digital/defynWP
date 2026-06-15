<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\DismissedVulnerabilitiesRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P4.3b — POST /defyn/v1/sites/{id}/vulnerabilities/dismiss.
 *
 * Toggles a per-site dismissal of a single finding identified by its fingerprint
 * (type, slug, source_id). dismissed=true inserts (validated against the current
 * snapshot); dismissed=false restores (always allowed). Ownership-gated.
 * Envelope: { data: { dismissed: bool }, error: null }.
 */
final class SitesVulnerabilitiesDismissController
{
    private const TYPES = ['plugin', 'theme', 'core'];

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $body      = $request->get_json_params() ?: [];
        $type      = isset($body['type']) ? (string) $body['type'] : '';
        $slug      = isset($body['slug']) ? (string) $body['slug'] : '';
        $sourceId  = isset($body['source_id']) ? (string) $body['source_id'] : '';
        $dismissed = $body['dismissed'] ?? null;

        if (!is_bool($dismissed) || !in_array($type, self::TYPES, true) || $slug === '' || $sourceId === '') {
            return ErrorResponse::create(
                400,
                'vulnerabilities.invalid_payload',
                'Body must include type, slug, source_id and a boolean "dismissed".'
            );
        }

        $repo = new DismissedVulnerabilitiesRepository();
        $now  = gmdate('Y-m-d H:i:s');

        if ($dismissed) {
            $finding = null;
            foreach ((new SiteVulnerabilitiesRepository())->findForSite($siteId) as $v) {
                if ($v->type === $type && $v->slug === $slug && $v->sourceId === $sourceId) {
                    $finding = $v;
                    break;
                }
            }
            if ($finding === null) {
                return ErrorResponse::create(
                    400,
                    'vulnerabilities.unknown_finding',
                    'No such finding in the current scan snapshot.'
                );
            }
            $repo->dismiss($siteId, $type, $slug, $sourceId, $userId, $now);
            (new ActivityLogger())->log($userId, $siteId, 'site.vulnerability_dismissed', [
                'type'           => $type,
                'slug'           => $slug,
                'source_id'      => $sourceId,
                'component_name' => $finding->componentName,
            ]);
        } else {
            $repo->restore($siteId, $type, $slug, $sourceId);
            (new ActivityLogger())->log($userId, $siteId, 'site.vulnerability_restored', [
                'type'      => $type,
                'slug'      => $slug,
                'source_id' => $sourceId,
            ]);
        }

        return new WP_REST_Response(['data' => ['dismissed' => $dismissed], 'error' => null], 200);
    }
}
