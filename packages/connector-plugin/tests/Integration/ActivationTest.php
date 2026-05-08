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
}
