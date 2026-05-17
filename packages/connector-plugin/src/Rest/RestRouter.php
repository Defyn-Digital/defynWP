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
}
