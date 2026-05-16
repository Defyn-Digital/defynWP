<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration;

use Defyn\Connector\Activation;
use Defyn\Connector\Storage\ConnectorState;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class ActivationTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        (new ConnectorState())->reset();
    }

    public function testActivateGeneratesKeypairAndSetsUnconfiguredState(): void
    {
        Activation::activate();

        $state = (new ConnectorState())->all();

        self::assertSame('unconfigured', $state['state']);
        self::assertNotEmpty($state['site_public_key']);
        self::assertNotEmpty($state['site_private_key']);
        self::assertArrayHasKey('generated_at', $state);

        // Public key is base64 of 32 bytes (Ed25519 public key length).
        self::assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen(base64_decode($state['site_public_key'], true)));
    }

    public function testActivateIsIdempotent_existingKeypairIsPreserved(): void
    {
        Activation::activate();
        $first = (new ConnectorState())->all();

        Activation::activate();
        $second = (new ConnectorState())->all();

        self::assertSame($first['site_public_key'], $second['site_public_key']);
        self::assertSame($first['site_private_key'], $second['site_private_key']);
    }

    /**
     * Defensive idempotency: if the wp_option row exists but the keypair is missing
     * (corrupt / partial state), Activation must NOT regenerate — silently destroying
     * a private key the dashboard still trusts would break the connection.
     * Instead the row is preserved as-is and an admin notice is queued.
     */
    public function testActivateDoesNotRegenerateWhenStateRowExistsWithoutPublicKey(): void
    {
        (new ConnectorState())->save([
            'state'           => 'awaiting-handshake',
            'connection_code' => 'PARTIAL12345',
        ]);

        Activation::activate();

        $state = (new ConnectorState())->all();
        self::assertSame('awaiting-handshake', $state['state']);
        self::assertSame('PARTIAL12345', $state['connection_code']);
        self::assertArrayNotHasKey('site_public_key', $state);
        self::assertArrayNotHasKey('site_private_key', $state);
        // admin_notices hook should be queued to warn the operator.
        self::assertNotFalse(has_action('admin_notices'));
    }
}
