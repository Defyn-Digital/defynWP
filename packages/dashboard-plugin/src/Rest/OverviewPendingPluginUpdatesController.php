<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\SitePluginsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.7 — GET /defyn/v1/overview/pending-plugin-updates.
 *
 * Returns the flat list of eligible (site, plugin) pairs for the SPA's bulk
 * update confirm dialog. Rate-limited at 30/MINUTE (same shape as P2.5's
 * /overview because the SPA may fetch this on dialog open).
 *
 * Spec: docs/superpowers/specs/2026-06-09-p2-7-bulk-plugin-updates-design.md § 2.1
 */
final class OverviewPendingPluginUpdatesController
{
    public function __construct(
        private readonly SitePluginsRepository $plugins = new SitePluginsRepository(),
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
            $rows   = $this->plugins->findAllPendingUpdatesForUser($userId);

            return new WP_REST_Response([
                'pending_updates' => $rows,
                'generated_at'    => gmdate('Y-m-d H:i:s'),
            ], 200);
        } finally {
            ob_end_clean();
        }
    }
}
