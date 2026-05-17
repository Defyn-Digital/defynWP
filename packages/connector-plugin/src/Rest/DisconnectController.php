<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Dashboard-initiated disconnect (spec § 5.1).
 *
 * Wipes dashboard-side trust material (dashboard_public_key, connected_at)
 * and any in-flight connection-code state, transitioning back to
 * "unconfigured". CRITICAL: keeps the site's own keypair (site_public_key,
 * site_private_key) per F4 reset-handler precedent, so an operator can
 * immediately re-handshake by generating a new code via SettingsPage
 * without re-activating the plugin.
 */
final class DisconnectController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        (new ConnectorState())->update([
            'state'                => 'unconfigured',
            'dashboard_public_key' => '',
            'connected_at'         => '',
            'connection_code'      => '',
            'site_nonce'           => '',
            'code_created_at'      => 0,
            'code_expires_at'      => 0,
        ]);

        return new WP_REST_Response(null, 204);
    }
}
