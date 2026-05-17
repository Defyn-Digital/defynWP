<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest\Middleware;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Crypto\TransientNonceStore;
use Defyn\Connector\Crypto\VerificationResult;
use Defyn\Connector\Storage\ConnectorState;
use WP_Error;
use WP_REST_Request;

/**
 * Gates inbound /status, /heartbeat, /disconnect requests by verifying the
 * dashboard's Ed25519 signature per spec § 5.2.
 *
 * Returns WP_Error (with HTTP status in data['status']) so
 * RestRouter::normalizeErrorEnvelope (existing from F5) can rewrap to the
 * {error:{code,message}} envelope — permission_callbacks can only return
 * bool|WP_Error per the WP REST contract.
 *
 * Wired in Task 9 (GET /heartbeat) and Tasks 8/10 (/status, /disconnect).
 */
final class VerifySignatureMiddleware
{
    /**
     * @return true|WP_Error
     */
    public static function check(WP_REST_Request $request)
    {
        $state = new ConnectorState();
        if ($state->get('state', 'unconfigured') !== 'connected') {
            return new WP_Error(
                'connector.not_connected',
                'Connector is not currently connected to a dashboard.',
                ['status' => 404]
            );
        }

        $publicKey = (string) $state->get('dashboard_public_key', '');
        if ($publicKey === '') {
            // Defense in depth — state==connected with empty key is impossible
            // by construction, but treat it as not_connected anyway.
            return new WP_Error(
                'connector.not_connected',
                'Dashboard public key is missing.',
                ['status' => 404]
            );
        }

        // WP_REST_Request normalises header names to lowercase + underscores —
        // X-Defyn-Timestamp is fetched as 'x_defyn_timestamp'. Do not change.
        $headers = [
            'X-Defyn-Timestamp' => (string) ($request->get_header('x_defyn_timestamp') ?? ''),
            'X-Defyn-Nonce'     => (string) ($request->get_header('x_defyn_nonce') ?? ''),
            'X-Defyn-Signature' => (string) ($request->get_header('x_defyn_signature') ?? ''),
        ];

        $result = Signer::verifyRequest(
            $publicKey,
            strtoupper((string) $request->get_method()),
            (string) $request->get_route(),
            (string) $request->get_body(),
            $headers,
            new TransientNonceStore()
        );

        return match ($result) {
            VerificationResult::VALID             => true,
            VerificationResult::MISSING_HEADERS   => new WP_Error('connector.signature_missing', 'Required signing headers are missing or malformed.', ['status' => 401]),
            VerificationResult::EXPIRED_TIMESTAMP => new WP_Error('connector.signature_expired', 'Signed request timestamp is outside the accepted window.', ['status' => 401]),
            VerificationResult::REPLAYED_NONCE    => new WP_Error('connector.signature_replay', 'Nonce has already been used.', ['status' => 401]),
            VerificationResult::INVALID_SIGNATURE => new WP_Error('connector.signature_invalid', 'Signature does not match dashboard public key.', ['status' => 401]),
        };
    }
}
