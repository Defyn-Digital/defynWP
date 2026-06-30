<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /defyn/v1/sites/{id}/upgrade-diagnostics — user-scoped, synchronous passthrough.
 *
 * Signs and calls the connector's read-only /upgrade-diagnostics endpoint and
 * returns its body verbatim, so an operator can inspect a managed host's
 * filesystem/update environment over the authenticated API (curl/SPA) without
 * triggering a real upgrade. Read-only on both ends.
 *
 * Added in v0.2.5 alongside the connector endpoint. Requires the connector on
 * the target site to be v0.2.5+ (older connectors 404 the route — surfaced as
 * connector.diagnostics_unavailable).
 *
 * Ownership gate mirrors SitesPluginsListController: 404 sites.not_found when
 * the site doesn't exist or isn't owned by the authenticated user.
 */
final class SitesUpgradeDiagnosticsController
{
    private const TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly SignedHttpClient $http = new SignedHttpClient(),
        private readonly ?Vault $vault = null,
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        $site = $this->sites->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $vault      = $this->vault ?? new Vault(DEFYN_VAULT_KEY);
        $privateKey = $vault->decrypt((string) $site->ourPrivateKey);

        $url           = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/upgrade-diagnostics';
        $canonicalPath = '/defyn-connector/v1/upgrade-diagnostics';

        $response = $this->http->signedGet($url, $privateKey, $canonicalPath, self::TIMEOUT_SECONDS);

        // Transport error (DNS, connection refused, timeout).
        if ($response['status'] === 0) {
            return ErrorResponse::create(
                502,
                'connector.unreachable',
                'Could not reach the connector: ' . (string) ($response['error'] ?? 'unknown transport error')
            );
        }

        // Older connector without the endpoint.
        if ($response['status'] === 404) {
            return ErrorResponse::create(
                502,
                'connector.diagnostics_unavailable',
                'The connector on this site does not expose upgrade diagnostics. Install connector v0.2.5 or later.'
            );
        }

        if ($response['status'] >= 400) {
            $message = $response['body']['error']['message'] ?? "Connector returned HTTP {$response['status']}.";
            return ErrorResponse::create(502, 'connector.error', (string) $message);
        }

        return new WP_REST_Response([
            'site_id'     => $siteId,
            'diagnostics' => $response['body'],
        ], 200);
    }
}
