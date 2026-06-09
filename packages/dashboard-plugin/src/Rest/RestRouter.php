<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Middleware\Cors;
use Defyn\Dashboard\Rest\Middleware\RateLimit;
use Defyn\Dashboard\Rest\Middleware\RequireAuth;
use Defyn\Dashboard\Rest\SitesCoreAllowMajorController;
use Defyn\Dashboard\Rest\SitesCoreRefreshController;
use Defyn\Dashboard\Rest\SitesCoreUpdateController;
use Defyn\Dashboard\Rest\SitesThemesController;
use Defyn\Dashboard\Rest\SitesThemesRefreshController;
use Defyn\Dashboard\Rest\SitesThemesUpdateController;

/**
 * Single registration point for every REST route in the plugin.
 *
 * Plugin::boot() instantiates this and calls register() on `rest_api_init`.
 * Adding a new endpoint = adding one line to register().
 */
final class RestRouter
{
    public const NAMESPACE = 'defyn/v1';

    public function register(): void
    {
        add_filter('rest_pre_serve_request', [Cors::class, 'apply'], 10, 4);

        // Normalize permission_callback failures (which can only return WP_Error) to
        // the same {error: {code, message}} envelope our controllers use via
        // ErrorResponse::create. Without this filter the SPA would see WP's native
        // WP_Error shape on auth-middleware rejections but the spec'd envelope on
        // controller-emitted errors — silent inconsistency.
        add_filter('rest_request_after_callbacks', [self::class, 'normalizeErrorEnvelope'], 10, 3);

        // F10: WP itself short-circuits with 404 (rest_no_route) and 405
        // (rest_no_method) BEFORE any handler/permission_callback runs, so the
        // rest_request_after_callbacks filter above never sees them. Hook
        // rest_post_dispatch to rewrap those specific WP-native shapes for
        // defyn/v1 routes only, so the SPA always sees {error:{code,message}}.
        add_filter('rest_post_dispatch', [self::class, 'normalizeRouteNotFound'], 10, 3);

        // Post-foundation: ensure no upstream cache (Kinsta full-page cache,
        // Cloudflare edge, WP.com Batcache when the connector is hosted on
        // Atomic) ever caches a defyn/v1 response. Caching produced the
        // "Failed to fetch" + stale sites-list bugs found during the first
        // live deploy: Kinsta's edge served a pre-handshake empty sites list
        // for minutes after the first site connected, and WP.com Batcache
        // served pre-handshake 404s on the connector's /status to the
        // dashboard. Setting explicit no-store headers on every dashboard
        // REST response is the cheapest defensive fix; the connector plugin
        // gets the same treatment in its own RestRouter.
        add_filter('rest_post_dispatch', [self::class, 'applyNoCacheHeaders'], 11, 3);

        register_rest_route(self::NAMESPACE, '/auth/login', [
            'methods'             => 'POST',
            'callback'            => [new AuthLoginController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'login'],
            'args'                => AuthLoginController::args(),
        ]);

        register_rest_route(self::NAMESPACE, '/auth/me', [
            'methods'             => 'GET',
            'callback'            => [new AuthMeController(), 'handle'],
            'permission_callback' => [RequireAuth::class, 'check'],
        ]);

        register_rest_route(self::NAMESPACE, '/auth/refresh', [
            'methods'             => 'POST',
            'callback'            => [new AuthRefreshController(), 'handle'],
            'permission_callback' => '__return_true',  // cookie-validated inside the controller
        ]);

        register_rest_route(self::NAMESPACE, '/auth/logout', [
            'methods'             => 'POST',
            'callback'            => [new AuthLogoutController(), 'handle'],
            'permission_callback' => '__return_true',  // idempotent
        ]);

        register_rest_route(self::NAMESPACE, '/sites', [
            'methods'             => 'POST',
            'callback'            => [new SitesCreateController(), 'handle'],
            'permission_callback' => [RequireAuth::class, 'check'],
        ]);

        // Combined-methods registration: GET (F5 SitesShow) + DELETE (F8 SitesDelete)
        // must share the same route pattern. WP REST requires multiple methods on
        // one path to be registered as a list of method-descriptors in a single
        // register_rest_route call — registering them separately would clobber.
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [new SitesShowController(), 'handle'],
                'permission_callback' => [RequireAuth::class, 'check'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [new SitesDeleteController(), 'handle'],
                'permission_callback' => [RequireAuth::class, 'check'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/sites', [
            'methods'             => 'GET',
            'callback'            => [new SitesListController(), 'handle'],
            'permission_callback' => [RequireAuth::class, 'check'],
        ]);

        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/sync', [
            'methods'             => 'POST',
            'callback'            => [new SitesSyncController(), 'handle'],
            'permission_callback' => [RequireAuth::class, 'check'],
        ]);

        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/ping', [
            'methods'             => 'POST',
            'callback'            => [new SitesPingController(), 'handle'],
            'permission_callback' => [RequireAuth::class, 'check'],
        ]);

        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/activity', [
            'methods'             => 'GET',
            'callback'            => [new SitesActivityController(), 'handle'],
            'permission_callback' => [RequireAuth::class, 'check'],
        ]);

        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/plugins', [
            'methods'             => 'GET',
            'callback'            => [new SitesPluginsListController(), 'handle'],
            'permission_callback' => [RequireAuth::class, 'check'],
        ]);

        // P2.1 — operator-triggered refresh. RateLimit::pluginsRefresh chains
        // RequireAuth::check internally (so no separate auth permission_callback)
        // and adds a per-(user, site) 6/min throttle on top.
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/plugins/refresh', [
            'methods'             => 'POST',
            'callback'            => [new SitesPluginsRefreshController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'pluginsRefresh'],
        ]);

        // P2.2 — per-plugin update trigger. Same auth-chain pattern as the
        // refresh route above; RateLimit::pluginsUpdate adds a per-(user, site,
        // slug) 6/hour throttle on top of RequireAuth::check. The slug regex is
        // intentionally narrower than the connector's accepts-anything route —
        // dashboard-side defense-in-depth so a malformed slug 404s at the route
        // layer instead of reaching the controller's inventory lookup.
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
            'methods'             => 'POST',
            'callback'            => [new SitesPluginsUpdateController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'pluginsUpdate'],
        ]);

        // P2.3 — per-site theme inventory, mirrors SitesPluginsListController
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/themes', [
            'methods'             => 'GET',
            'callback'            => [new SitesThemesController(), 'handle'],
            'permission_callback' => [RequireAuth::class, 'check'],
        ]);

        // P2.3 — operator-triggered theme refresh. RateLimit::sitesThemesRefresh chains
        // RequireAuth::check internally (so no separate auth permission_callback)
        // and adds a per-(user, site) 6/hour throttle on top.
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/themes/refresh', [
            'methods'             => 'POST',
            'callback'            => [new SitesThemesRefreshController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'sitesThemesRefresh'],
        ]);

        // P2.3 — per-theme update trigger. Same auth-chain pattern as the refresh
        // route above; RateLimit::themesUpdate adds a per-(user, site) 6/hour
        // throttle on top of RequireAuth::check. The slug regex is intentionally
        // narrower than the connector's accepts-anything route — dashboard-side
        // defense-in-depth so a malformed slug 404s at the route layer instead of
        // reaching the controller's inventory lookup.
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/themes/(?P<slug>[a-z0-9-]{1,80})/update', [
            'methods'             => 'POST',
            'callback'            => [new SitesThemesUpdateController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'themesUpdate'],
        ]);

        // P2.4 — operator-triggered core refresh. RateLimit::sitesCoreRefresh chains
        // RequireAuth::check internally and adds a per-(user, site) 6/hour throttle
        // on top, separate bucket from pluginsRefresh and sitesThemesRefresh.
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/core/refresh', [
            'methods'             => 'POST',
            'callback'            => [new SitesCoreRefreshController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'sitesCoreRefresh'],
        ]);

        // P2.4 — per-core-update trigger. Same auth-chain pattern as the refresh
        // route above; RateLimit::coreUpdate adds a per-(user, site) 3/hour throttle
        // on top of RequireAuth::check (tighter than plugins/themes due to weight).
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/core/update', [
            'methods'             => 'POST',
            'callback'            => [new SitesCoreUpdateController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'coreUpdate'],
        ]);

        // P2.4.1 — toggle per-site allow_major flag. RateLimit::coreAllowMajor chains
        // RequireAuth::check internally and adds a per-(user, site) 10/hour throttle —
        // looser than coreUpdate (3/hr) because toggling is a cheap metadata write, not
        // a heavyweight upgrade. Separate bucket from coreUpdate per spec § 4.8.
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/core/allow-major', [
            'methods'             => 'POST',
            'callback'            => [new SitesCoreAllowMajorController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'coreAllowMajor'],
        ]);

        // P2.5 — read-only operator overview. RateLimit::overview chains
        // RequireAuth::check internally and adds a per-user 30/MINUTE throttle —
        // FIRST per-minute bucket in the project (all prior buckets are per-hour).
        // The SPA polls this endpoint every 60s while the tab is active.
        register_rest_route(self::NAMESPACE, '/overview', [
            'methods'             => 'GET',
            'callback'            => [new OverviewController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'overview'],
        ]);

        // P2.6 — bulk fan-out: POST /overview/sync-all. Schedules the existing
        // `defyn_sync_site` AS job per owned site and emits ONE fleet-scoped
        // activity event (site_id=null). RateLimit::overviewSyncAll is 10/HOUR.
        register_rest_route(self::NAMESPACE, '/overview/sync-all', [
            'methods'             => 'POST',
            'callback'            => [new OverviewSyncAllController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'overviewSyncAll'],
        ]);

        // P2.7 — GET /overview/pending-plugin-updates. Returns the flat list of
        // eligible (site, plugin) pairs for the SPA's bulk update confirm dialog.
        // RateLimit::overviewPendingPluginUpdates is 30/MINUTE.
        register_rest_route(self::NAMESPACE, '/overview/pending-plugin-updates', [
            'methods'             => 'GET',
            'callback'            => [new OverviewPendingPluginUpdatesController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'overviewPendingPluginUpdates'],
        ]);

        // P2.7 — POST /overview/bulk-update-plugins. Fan-outs the P2.2 UpdateSitePlugin
        // AS job per confirmed pair; emits ONE fleet-scoped activity event.
        // RateLimit::bulkPluginUpdate is 5/HOUR.
        register_rest_route(self::NAMESPACE, '/overview/bulk-update-plugins', [
            'methods'             => 'POST',
            'callback'            => [new OverviewBulkUpdatePluginsController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'bulkPluginUpdate'],
        ]);

        // P2.8 — GET /overview/pending-theme-updates. Returns the flat list of
        // eligible (site, theme) pairs for the SPA's bulk theme-update confirm dialog.
        // RateLimit::overviewPendingThemeUpdates is 30/MINUTE — mirror of P2.7's
        // overviewPendingPluginUpdates because the SPA fetches this on dialog open.
        register_rest_route(self::NAMESPACE, '/overview/pending-theme-updates', [
            'methods'             => 'GET',
            'callback'            => [new OverviewPendingThemeUpdatesController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'overviewPendingThemeUpdates'],
        ]);

        register_rest_route(self::NAMESPACE, '/activity', [
            'methods'             => 'GET',
            'callback'            => [new ActivityListController(), 'handle'],
            'permission_callback' => [RequireAuth::class, 'check'],
        ]);
    }

    /**
     * If any defyn/v1 handler/permission callback returned a WP_Error, rewrap the
     * resulting body as { error: { code, message } } to match the spec envelope.
     *
     * @param mixed           $response  Result of the handler. WP_HTTP_Response on success, WP_Error on failure.
     * @param array           $handler   Route handler descriptor.
     * @param \WP_REST_Request $request  The original request.
     * @return mixed
     */
    public static function normalizeErrorEnvelope($response, $handler, $request)
    {
        if (!$request instanceof \WP_REST_Request) {
            return $response;
        }
        if (strpos($request->get_route(), '/' . self::NAMESPACE) !== 0) {
            return $response;
        }
        if (!is_wp_error($response)) {
            return $response;
        }

        $status = (int) ($response->get_error_data()['status'] ?? 500);
        return Responses\ErrorResponse::create(
            $status,
            (string) $response->get_error_code(),
            (string) $response->get_error_message()
        );
    }

    /**
     * Rewrap WP-native 404 (rest_no_route) + 405 (rest_no_method) responses for
     * routes under defyn/v1/* so the SPA sees the same {error:{code,message}}
     * envelope as every other defyn-emitted error (spec § 9.1).
     *
     * F5 only normalized errors that flowed through controllers / permission
     * callbacks (rest_request_after_callbacks). 404/405 come from the dispatcher
     * BEFORE any handler runs, so F5's filter didn't catch them.
     *
     * Note: WP_REST_Server itself only emits rest_no_route (404) — it does NOT
     * distinguish path-mismatch from method-mismatch. The 405 branch below is
     * defensive coverage in case a third-party plugin (or a future WP release)
     * surfaces rest_no_method.
     *
     * @param \WP_REST_Response $response
     * @param \WP_REST_Server   $server
     * @param \WP_REST_Request  $request
     * @return \WP_REST_Response
     */
    public static function normalizeRouteNotFound($response, $server, $request)
    {
        if (!$response instanceof \WP_REST_Response) {
            return $response;
        }
        if (!$request instanceof \WP_REST_Request) {
            return $response;
        }
        if (strpos($request->get_route(), '/' . self::NAMESPACE) !== 0) {
            return $response;
        }
        $status = $response->get_status();
        if ($status === 404) {
            $data = $response->get_data();
            if (is_array($data) && isset($data['code']) && $data['code'] === 'rest_no_route') {
                $response->set_data([
                    'error' => [
                        'code'    => 'rest.route_not_found',
                        'message' => 'Route not found.',
                    ],
                ]);
            }
        } elseif ($status === 405) {
            $data = $response->get_data();
            if (is_array($data) && isset($data['code']) && $data['code'] === 'rest_no_method') {
                $response->set_data([
                    'error' => [
                        'code'    => 'rest.method_not_allowed',
                        'message' => 'Method not allowed.',
                    ],
                ]);
            }
        }
        return $response;
    }

    /**
     * Forbid every upstream cache from storing defyn/v1 responses.
     *
     * Why this exists: REST API responses are *dynamic* (they depend on the
     * authenticated user, the current site state, and recently-written DB
     * rows). When Kinsta's edge cache or WP.com Batcache stores them they
     * survive long after the underlying state changes — the SPA sees stale
     * data and we see "Failed to fetch" when cached responses replay without
     * CORS headers attached. Setting Cache-Control: no-store + private (and
     * the legacy Pragma/Expires pair for older intermediaries) on every
     * defyn/v1 response is the simplest cross-host-compatible fix.
     *
     * Sets headers on the WP_REST_Response object directly (rather than
     * calling nocache_headers()) so the headers survive the response
     * serialization path even if a downstream filter rebuilds it.
     *
     * @param \WP_REST_Response $response
     * @param \WP_REST_Server   $server
     * @param \WP_REST_Request  $request
     * @return \WP_REST_Response
     */
    public static function applyNoCacheHeaders($response, $server, $request)
    {
        if (!$response instanceof \WP_REST_Response) {
            return $response;
        }
        if (!$request instanceof \WP_REST_Request) {
            return $response;
        }
        if (strpos($request->get_route(), '/' . self::NAMESPACE) !== 0) {
            return $response;
        }
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
        $response->header('Pragma', 'no-cache');
        $response->header('Expires', '0');
        return $response;
    }
}
