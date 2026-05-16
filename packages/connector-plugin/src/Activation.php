<?php

declare(strict_types=1);

namespace Defyn\Connector;

use Defyn\Connector\Crypto\KeyPair;
use Defyn\Connector\Storage\ConnectorState;

/**
 * Runs on plugin activation. Generates the site's Ed25519 keypair on first
 * activation and sets state = "unconfigured". Idempotent — repeated activations
 * (e.g. plugin update) preserve the existing keypair.
 *
 * Defensive: if a state row exists but is missing the keypair (corrupt or
 * partially-written state), this method REFUSES to regenerate — destroying
 * a private key the dashboard might still trust would break the connection
 * silently. Instead, an admin notice is queued asking the operator to manually
 * clear the wp_option before re-activating.
 */
final class Activation
{
    public static function activate(): void
    {
        $state = new ConnectorState();

        if ($state->exists()) {
            if (!$state->get('site_public_key')) {
                // Partial / corrupt state — preserve as-is rather than silently
                // destroying any private key that might still be in use.
                add_action('admin_notices', static function (): void {
                    echo '<div class="notice notice-error"><p>';
                    echo '<strong>DefynWP Connector:</strong> The <code>defyn_connector</code> wp_option row exists ';
                    echo 'but is missing the site keypair. Refusing to regenerate (would destroy crypto identity). ';
                    echo 'To recover: manually delete the <code>defyn_connector</code> row in <code>wp_options</code>, then re-activate the plugin.';
                    echo '</p></div>';
                });
            }
            return;  // keypair already generated OR partial state — never overwrite
        }

        $pair = KeyPair::generate();
        $state->save([
            'state'            => 'unconfigured',
            'site_public_key'  => $pair['public_key'],
            'site_private_key' => $pair['private_key'],
            'generated_at'     => gmdate('c'),
        ]);
    }
}
