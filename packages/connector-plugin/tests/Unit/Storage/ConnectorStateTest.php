<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\Storage;

use Defyn\Connector\Storage\ConnectorState;
use WP_UnitTestCase;

/**
 * @group unit
 *
 * Uses WP_UnitTestCase because ConnectorState reads/writes wp_options;
 * we want the real options API behavior, not a hand-rolled fake.
 */
final class ConnectorStateTest extends WP_UnitTestCase
{
    private ConnectorState $state;

    public function setUp(): void
    {
        parent::setUp();
        $this->state = new ConnectorState();
        $this->state->reset();
    }

    public function testInitiallyHasNoState(): void
    {
        self::assertFalse($this->state->exists());
        self::assertSame([], $this->state->all());
    }

    public function testSavePersistsArrayAsJson(): void
    {
        $this->state->save(['state' => 'unconfigured', 'public_key' => 'abc']);

        self::assertTrue($this->state->exists());
        self::assertSame('unconfigured', $this->state->get('state'));
        self::assertSame('abc', $this->state->get('public_key'));
    }

    public function testUpdateMergesIntoExistingState(): void
    {
        $this->state->save(['state' => 'unconfigured', 'public_key' => 'abc']);
        $this->state->update(['state' => 'awaiting-handshake', 'code' => 'XYZ']);

        $all = $this->state->all();
        self::assertSame('awaiting-handshake', $all['state']);
        self::assertSame('abc', $all['public_key']);  // preserved
        self::assertSame('XYZ', $all['code']);        // added
    }

    public function testResetClearsState(): void
    {
        $this->state->save(['state' => 'awaiting-handshake']);
        $this->state->reset();

        self::assertFalse($this->state->exists());
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        self::assertNull($this->state->get('missing'));
        self::assertSame('fallback', $this->state->get('missing', 'fallback'));
    }
}
