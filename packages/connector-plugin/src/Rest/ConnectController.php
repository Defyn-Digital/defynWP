<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\Rest\Responses\ErrorResponse;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles POST /defyn-connector/v1/connect.
 *
 * F4 scope: validates the posted code against locally-stored connector state
 * and marks it consumed. NO crypto challenge-response — that lands in F5.
 */
final class ConnectController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        $code = is_array($body) ? ($body['code'] ?? null) : null;

        if (!is_string($code) || $code === '') {
            return ErrorResponse::create(400, 'connector.missing_code', 'Missing or invalid code field.');
        }

        $state   = new ConnectorState();
        $stored  = (string) $state->get('connection_code', '');
        $current = (string) $state->get('state', 'unconfigured');

        if ($stored === '' || $current === 'unconfigured') {
            return ErrorResponse::create(404, 'connector.no_pending_code', 'No connection code has been generated yet.');
        }

        if (!hash_equals($stored, $code)) {
            return ErrorResponse::create(401, 'connector.invalid_code', 'Connection code does not match.');
        }

        // Spec § 8 step 7 ordering: "exist, not expired, not consumed" — expiry takes
        // precedence over consumption when a code is both. If a code outlived its 15-min
        // window AND was previously consumed, the user is told to regenerate (410) rather
        // than reminded it was already used (409). Both lead to the same UX (regenerate).
        $expiresAt = (int) $state->get('code_expires_at', 0);
        if ($expiresAt > 0 && time() >= $expiresAt) {
            return ErrorResponse::create(410, 'connector.code_expired', 'Connection code has expired. Generate a new one.');
        }

        if (!empty($state->get('code_consumed_at'))) {
            return ErrorResponse::create(409, 'connector.code_consumed', 'Connection code has already been consumed.');
        }

        $state->update([
            'state'            => 'code-consumed',
            'code_consumed_at' => time(),
        ]);

        return new WP_REST_Response(['ok' => true], 200);
    }
}
