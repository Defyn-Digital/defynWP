<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\ThemesRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SitesListController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $sites  = (new SitesRepository())->findAllForUser($userId);

        // v0.30.3 — per-site pending-update counts (team-shared fleet), merged
        // onto each site so the Overview/Sites lists can show the "N updates" pill.
        $pluginCounts = (new SitePluginsRepository())->updateCountsBySite();
        $themeCounts  = (new ThemesRepository())->updateCountsBySite();

        return new WP_REST_Response([
            'sites' => array_map(static function ($s) use ($pluginCounts, $themeCounts) {
                $j  = $s->toJson();
                $id = (int) ($j['id'] ?? 0);
                $j['plugin_updates'] = $pluginCounts[$id] ?? 0;
                $j['theme_updates']  = $themeCounts[$id] ?? 0;
                return $j;
            }, $sites),
        ], 200);
    }
}
