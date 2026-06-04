<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

/**
 * Single registration point for every REST route on the connector.
 *
 * Plugin::boot() instantiates this and calls register() on `rest_api_init`.
 * Adding a new endpoint = adding one line to register().
 */
final class RestRouter
{
    public const NAMESPACE = 'defyn-connector/v1';

    public function register(): void
    {
        // Normalize permission_callback failures (WP_Error only) to the same
        // {error: {code, message}} envelope our controllers use via ErrorResponse.
        add_filter('rest_request_after_callbacks', [self::class, 'normalizeErrorEnvelope'], 10, 3);

        // Post-foundation: forbid every upstream cache from storing
        // defyn-connector/v1 responses. The dashboard discovered (2026-06-04
        // smartcoding.com.au, hosted on WP.com Atomic) that Batcache happily
        // cached pre-handshake 404 responses on /status for 5 minutes,
        // causing the dashboard's signed sync calls to receive stale failures
        // long after the connector's state had transitioned to `connected`.
        // Setting no-store on every connector REST response keeps Batcache,
        // WP-Rocket, LiteSpeed, NGINX micro-cache, et al. out of the way.
        add_filter('rest_post_dispatch', [self::class, 'applyNoCacheHeaders'], 11, 3);

        register_rest_route(self::NAMESPACE, '/connect', [
            'methods'             => 'POST',
            'callback'            => [new ConnectController(), 'handle'],
            'permission_callback' => '__return_true',  // public; protected by code-validation logic in the controller
        ]);

        register_rest_route(self::NAMESPACE, '/status', [
            'methods'             => 'GET',
            'callback'            => [new StatusController(), 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ]);

        register_rest_route(self::NAMESPACE, '/heartbeat', [
            'methods'             => 'GET',
            'callback'            => [new HeartbeatController(), 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ]);

        register_rest_route(self::NAMESPACE, '/disconnect', [
            'methods'             => 'POST',
            'callback'            => [new DisconnectController(), 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ]);

        register_rest_route(self::NAMESPACE, '/plugins', [
            'methods'             => 'GET',
            'callback'            => [new PluginsListController(), 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ]);
    }

    /**
     * @param mixed            $response
     * @param array            $handler
     * @param \WP_REST_Request $request
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
     * Forbid every upstream cache from storing defyn-connector/v1 responses.
     *
     * Why this exists: REST API responses are *dynamic* (they depend on the
     * connector's current state and the signature/nonce in each request).
     * Upstream caches (WP.com Batcache, Kinsta full-page cache, Cloudflare
     * edge, WP-Rocket, LiteSpeed, NGINX micro-cache) that store these by URL
     * will replay stale 404/401/200 bodies — long after the connector has
     * transitioned state, accepted a fresh nonce, etc. Live evidence: on
     * smartcoding.com.au (WP.com Atomic), Batcache served pre-handshake 404
     * responses on /status for the dashboard's signed sync calls for the
     * full 5-minute TTL after a successful handshake.
     *
     * Setting headers on the WP_REST_Response object directly (rather than
     * calling nocache_headers()) ensures they survive the response
     * serialization path even if a downstream filter rebuilds the response.
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
