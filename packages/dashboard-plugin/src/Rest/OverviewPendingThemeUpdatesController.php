<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\ThemesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.8 — GET /defyn/v1/overview/pending-theme-updates.
 *
 * Returns the flat list of eligible (site, theme) pairs for the SPA's bulk
 * theme-update confirm dialog. Rate-limited at 30/MINUTE (same shape as
 * P2.7's pending-plugin-updates because the SPA fetches this on dialog open).
 *
 * Mirror of P2.7's OverviewPendingPluginUpdatesController with theme swap.
 *
 * Spec: docs/superpowers/specs/2026-06-09-p2-8-bulk-theme-updates-design.md § 2.1
 */
final class OverviewPendingThemeUpdatesController
{
    public function __construct(
        private readonly ThemesRepository $themes = new ThemesRepository(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Defensive STDOUT guard — carry-forward from P2.2 plan-bug #4.
        // Pure read endpoint, but a stray echo from a hooked filter would
        // still corrupt the JSON envelope.
        ob_start();
        try {
            $userId = (int) $request->get_param('_authenticated_user_id');
            $rows   = $this->themes->findAllPendingUpdatesForUser($userId);

            return new WP_REST_Response([
                'pending_updates' => $rows,
                'generated_at'    => gmdate('Y-m-d H:i:s'),
            ], 200);
        } finally {
            ob_end_clean();
        }
    }
}
