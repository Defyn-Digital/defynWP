<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\ThemesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.8 — POST /defyn/v1/overview/bulk-update-themes.
 *
 * Validates each (site_id, slug) pair in the request body, skips invalid pairs
 * with a structured reason, fan-outs the existing P2.3 `defyn_update_site_theme`
 * AS job per valid pair, and emits ONE fleet-scoped
 * `overview.bulk_theme_update_requested` activity event (site_id=null) ONLY
 * when scheduled_count > 0.
 *
 * Returns 202 when scheduled_count > 0, 200 with same envelope when 0 (all
 * skipped), 400 bulk.empty_updates on empty body, 429 bulk.rate_limited over
 * the bucket cap.
 *
 * Bypasses the per-(user, site) themesUpdate 6/HOUR bucket — operator's
 * explicit dialog confirmation IS the safety.
 *
 * Mirror of P2.7's OverviewBulkUpdatePluginsController with theme swap. Field
 * key STAYS `slug` (not stylesheet).
 *
 * Spec: docs/superpowers/specs/2026-06-09-p2-8-bulk-theme-updates-design.md § 2.2
 */
final class OverviewBulkUpdateThemesController
{
    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly ThemesRepository $themes = new ThemesRepository(),
        private readonly ActivityLogger $logger = new ActivityLogger(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Defensive STDOUT guard — P2.2 plan-bug #4 carry-forward.
        ob_start();
        try {
            $userId  = (int) $request->get_param('_authenticated_user_id');
            $body    = $request->get_json_params();
            $updates = $body['updates'] ?? null;

            if (!is_array($updates) || count($updates) === 0) {
                return ErrorResponse::create(400, 'bulk.empty_updates', 'updates array must be non-empty');
            }

            $scheduled = [];
            $skipped   = [];

            foreach ($updates as $pair) {
                $siteId = (int) ($pair['site_id'] ?? 0);
                $slug   = (string) ($pair['slug'] ?? '');

                if ($this->sites->findByIdForUser($siteId, $userId) === null) {
                    $skipped[] = ['site_id' => $siteId, 'slug' => $slug, 'reason' => 'site_not_owned'];
                    continue;
                }
                $row = $this->themes->findRowForSiteAndSlug($siteId, $slug);
                if ($row === null) {
                    $skipped[] = ['site_id' => $siteId, 'slug' => $slug, 'reason' => 'theme_not_found'];
                    continue;
                }
                if ((int) ($row['update_available'] ?? 0) !== 1) {
                    $skipped[] = ['site_id' => $siteId, 'slug' => $slug, 'reason' => 'no_update_available'];
                    continue;
                }

                as_schedule_single_action(time(), 'defyn_update_site_theme', [$siteId, $slug, 0], 'defyn');
                $scheduled[] = ['site_id' => $siteId, 'slug' => $slug];
            }

            if (count($scheduled) > 0) {
                $this->logger->log(
                    $userId,
                    null,                                              // fleet-scoped — guardrail #4
                    'overview.bulk_theme_update_requested',            // exact string — guardrail #1
                    [
                        'scheduled_count' => count($scheduled),
                        'skipped_count'   => count($skipped),
                        'pairs'           => array_values($scheduled),
                    ]
                );
            }

            return new WP_REST_Response(
                [
                    'scheduled_count' => count($scheduled),
                    'skipped_count'   => count($skipped),
                    'scheduled_pairs' => array_values($scheduled),
                    'skipped_pairs'   => array_values($skipped),
                    'scheduled_at'    => gmdate('Y-m-d H:i:s'),
                ],
                count($scheduled) > 0 ? 202 : 200
            );
        } finally {
            ob_end_clean();
        }
    }
}
