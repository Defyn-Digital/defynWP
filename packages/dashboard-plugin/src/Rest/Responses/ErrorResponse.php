<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest\Responses;

use WP_REST_Response;

/**
 * Builds a consistent error envelope: { error: { code, message, details? } }.
 * HTTP status is the constructor's $status arg.
 */
final class ErrorResponse
{
    public static function create(int $status, string $code, string $message, ?array $details = null): WP_REST_Response
    {
        $body = [
            'error' => [
                'code'    => $code,
                'message' => $message,
            ],
        ];
        if ($details !== null) {
            $body['error']['details'] = $details;
        }
        return new WP_REST_Response($body, $status);
    }
}
