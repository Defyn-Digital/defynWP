<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

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
        // Normalize permission_callback failures (which can only return WP_Error) to
        // the same {error: {code, message}} envelope our controllers use via
        // ErrorResponse::create. Without this filter the SPA would see WP's native
        // WP_Error shape on auth-middleware rejections but the spec'd envelope on
        // controller-emitted errors — silent inconsistency.
        add_filter('rest_request_after_callbacks', [self::class, 'normalizeErrorEnvelope'], 10, 3);

        register_rest_route(self::NAMESPACE, '/auth/login', [
            'methods'             => 'POST',
            'callback'            => [new AuthLoginController(), 'handle'],
            'permission_callback' => '__return_true',  // public endpoint
            'args'                => AuthLoginController::args(),
        ]);

        register_rest_route(self::NAMESPACE, '/auth/me', [
            'methods'             => 'GET',
            'callback'            => [new AuthMeController(), 'handle'],
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
}
