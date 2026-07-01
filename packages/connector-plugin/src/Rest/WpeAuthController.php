<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\Wpe\WpeAuth;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /defyn-connector/v1/wpe-auth — signed, READ-ONLY.
 *
 * Added in v0.2.6. Returns whether this site is on WP Engine and, if so, the
 * short-lived cookies the dashboard must attach to its update request so WP
 * Engine permits the filesystem-modifying upgrade (see WpeAuth). Off WP Engine
 * it returns is_wpengine=false and no cookies, and the dashboard sends none.
 *
 * Signature-gated via VerifySignatureMiddleware (permission_callback). The
 * response travels over TLS to the authenticated dashboard only.
 *
 * NOTE (hardening, see PR): the cookies are admin-equivalent and currently
 * protected by TLS + the short TTL + the signed channel. A future pass can
 * additionally encrypt them to the dashboard's public key (as ManageWP does),
 * for defence-in-depth.
 */
final class WpeAuthController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $isWpe = WpeAuth::isWpEngine();

        return new WP_REST_Response([
            'is_wpengine' => $isWpe,
            'cookies'     => $isWpe ? WpeAuth::cookies() : [],
            'expires_at'  => $isWpe ? time() + WpeAuth::COOKIE_TTL : 0,
            'server_time' => time(),
        ], 200);
    }
}
