<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\UpdateSiteTheme;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\ThemesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.3 — POST /defyn/v1/sites/{id}/themes/{slug}/update.
 *
 * Spec: docs/superpowers/specs/2026-06-06-p2-3-design.md §5.3
 */
final class SitesThemesUpdateController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        $slug   = (string) $request->get_param('slug');

        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $repo = new ThemesRepository();
        $row  = $repo->findRowForSiteAndSlug($siteId, $slug);
        if ($row === null) {
            return ErrorResponse::create(
                404,
                'themes.not_found_in_inventory',
                sprintf('Theme "%s" is not in this site\'s inventory.', $slug),
            );
        }

        if ((int) $row['update_available'] === 0) {
            return ErrorResponse::create(
                409,
                'themes.no_update_available_for_slug',
                sprintf('No update available for "%s".', $slug),
            );
        }

        if (in_array($row['update_state'], ['queued', 'updating'], true)) {
            return ErrorResponse::create(
                409,
                'themes.update_in_progress',
                sprintf('Update for "%s" is already in progress.', $slug),
            );
        }

        $now = gmdate('Y-m-d H:i:s');
        $repo->markUpdateRequested($siteId, $slug, $now);

        (new ActivityLogger())->log($userId, $siteId, 'theme_update.requested', [
            'slug'         => $slug,
            'from_version' => $row['version'] ?? null,
            'to_version'   => $row['update_version'] ?? null,
        ]);

        \as_schedule_single_action(time(), UpdateSiteTheme::HOOK, [$siteId, $slug, 0]);

        return new WP_REST_Response([
            'scheduled'    => true,
            'site_id'      => $siteId,
            'slug'         => $slug,
            'update_state' => 'queued',
        ], 202);
    }
}
