<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\UpdateSiteCore;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.4 — POST /defyn/v1/sites/{id}/core/update.
 *
 * Spec: docs/superpowers/specs/2026-06-07-p2-4-core-updates-design.md §5.2
 */
final class SitesCoreUpdateController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        $repo = new SitesRepository();
        $site = $repo->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        if (!$site->coreUpdateAvailable) {
            return ErrorResponse::create(
                409,
                'core.no_update_available_for_site',
                'No WordPress core update is available for this site.',
            );
        }

        if (in_array($site->coreUpdateState, ['queued', 'updating'], true)) {
            return ErrorResponse::create(
                409,
                'core.update_in_progress',
                'A core update for this site is already in progress.',
            );
        }

        $currentVersion = (string) ($site->wpVersion ?? '');
        $targetVersion  = (string) ($site->coreUpdateVersion ?? '');
        if (!self::isMinorUpgrade($currentVersion, $targetVersion)) {
            return ErrorResponse::create(
                409,
                'core.major_update_blocked',
                sprintf(
                    'Major-version updates (%s -> %s) require P2.4.1.',
                    $currentVersion,
                    $targetVersion
                ),
            );
        }

        $now = gmdate('Y-m-d H:i:s');
        $repo->markCoreUpdateRequested($siteId, $now);

        (new ActivityLogger())->log($userId, $siteId, 'core_update.requested', [
            'from_version' => $currentVersion,
            'to_version'   => $targetVersion,
        ]);

        \as_schedule_single_action(time(), UpdateSiteCore::HOOK, [$siteId, 0]);

        return new WP_REST_Response([
            'scheduled'         => true,
            'site_id'           => $siteId,
            'core_update_state' => 'queued',
        ], 202);
    }

    private static function isMinorUpgrade(string $current, string $target): bool
    {
        [$cMaj, $cMin] = array_pad(array_slice(explode('.', $current), 0, 2), 2, '0');
        [$tMaj, $tMin] = array_pad(array_slice(explode('.', $target), 0, 2), 2, '0');
        return $cMaj === $tMaj && $cMin === $tMin;
    }
}
