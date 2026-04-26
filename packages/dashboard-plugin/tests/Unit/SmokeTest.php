<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testToolchainWorks(): void
    {
        self::assertSame(2, 1 + 1);
    }
}
