<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Rest\Responses\ErrorResponse;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles POST /defyn-connector/v1/connect.
 *
 * F4 → F5 evolution: now performs the full handshake.
 *   F4: validated code, returned {ok: true}, state → code-consumed.
 *   F5: also accepts dashboard_public_key + callback_challenge, signs
 *       the challenge with K_site, persists dashboard_public_key, returns
 *       {site_public_key, challenge_signature, site_url, site_name},
 *       state → connected.
 */
final class ConnectController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        $code            = is_array($body) ? ($body['code'] ?? null) : null;
        $dashboardPubB64 = is_array($body) ? ($body['dashboard_public_key'] ?? null) : null;
        $challengeB64    = is_array($body) ? ($body['callback_challenge'] ?? null) : null;

        if (!is_string($code) || $code === '') {
            return ErrorResponse::create(400, 'connector.missing_code', 'Missing or invalid code field.');
        }
        if (!is_string($dashboardPubB64) || $dashboardPubB64 === '') {
            return ErrorResponse::create(400, 'connector.missing_dashboard_key', 'Missing dashboard_public_key field.');
        }
        if (!is_string($challengeB64) || $challengeB64 === '') {
            return ErrorResponse::create(400, 'connector.missing_challenge', 'Missing callback_challenge field.');
        }

        // Validate dashboard public key well-formedness (must be base64 of 32 bytes).
        $dashboardPubRaw = base64_decode($dashboardPubB64, true);
        if ($dashboardPubRaw === false || strlen($dashboardPubRaw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return ErrorResponse::create(400, 'connector.invalid_dashboard_key', 'dashboard_public_key is not a valid 32-byte base64 Ed25519 key.');
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

        // Spec § 8 step 7 ordering: expired-before-consumed (locked in by F4 cleanup commit 90aee1a).
        $expiresAt = (int) $state->get('code_expires_at', 0);
        if ($expiresAt > 0 && time() >= $expiresAt) {
            return ErrorResponse::create(410, 'connector.code_expired', 'Connection code has expired. Generate a new one.');
        }

        if (!empty($state->get('code_consumed_at'))) {
            return ErrorResponse::create(409, 'connector.code_consumed', 'Connection code has already been consumed.');
        }

        // Happy path: sign the dashboard's challenge with K_site, persist handshake state.
        $privateKeyBase64 = (string) $state->get('site_private_key', '');
        $signature = Signer::sign($challengeB64, $privateKeyBase64);

        $now = time();
        $state->update([
            'state'                => 'connected',
            'code_consumed_at'     => $now,
            'dashboard_public_key' => $dashboardPubB64,
            'connected_at'         => gmdate('c', $now),
        ]);

        return new WP_REST_Response([
            'site_public_key'     => (string) $state->get('site_public_key', ''),
            'challenge_signature' => $signature,
            'site_url'            => get_site_url(),
            'site_name'           => get_bloginfo('name'),
        ], 200);
    }
}
