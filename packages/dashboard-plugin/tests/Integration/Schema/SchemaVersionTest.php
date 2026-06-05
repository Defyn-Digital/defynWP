<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Schema\SchemaVersion;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * @group integration
 */
final class SchemaVersionTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        delete_option(SchemaVersion::OPTION);
    }

    public function testCurrentReturnsOneWhenOptionAbsent(): void
    {
        self::assertSame(1, SchemaVersion::current());
    }

    public function testSetPersistsVersion(): void
    {
        SchemaVersion::set(2);
        self::assertSame(2, SchemaVersion::current());
    }

    public function testNeedsMigrationToTrueWhenBelowTarget(): void
    {
        SchemaVersion::set(1);
        self::assertTrue(SchemaVersion::needsMigrationTo(2));
        SchemaVersion::set(2);
        self::assertFalse(SchemaVersion::needsMigrationTo(2));
    }
}
