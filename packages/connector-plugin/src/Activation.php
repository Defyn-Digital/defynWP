<?php

declare(strict_types=1);

namespace Defyn\Connector;

use Defyn\Connector\Crypto\KeyPair;
use Defyn\Connector\Storage\ConnectorState;

/**
 * Runs on plugin activation. Generates the site's Ed25519 keypair on first
 * activation and sets state = "unconfigured". Idempotent — repeated activations
 * (e.g. plugin update) preserve the existing keypair.
 */
final class Activation
{
    public static function activate(): void
    {
        $state = new ConnectorState();
        if ($state->exists() && $state->get('site_public_key')) {
            return;  // keypair already generated; preserve it
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
