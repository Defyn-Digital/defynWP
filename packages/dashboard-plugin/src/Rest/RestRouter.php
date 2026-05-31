<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Middleware\Cors;
use Defyn\Dashboard\Rest\Middleware\RateLimit;
use Defyn\Dashboard\Rest\Middleware\RequireAuth;

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
}
