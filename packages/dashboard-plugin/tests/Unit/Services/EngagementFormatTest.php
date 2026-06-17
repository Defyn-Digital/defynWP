<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Services;

use Defyn\Dashboard\Services\EngagementFormat;
use PHPUnit\Framework\TestCase;

final class EngagementFormatTest extends TestCase
{
    /** @dataProvider cases */
    public function testFormat(float $seconds, string $expected): void
    {
        self::assertSame($expected, EngagementFormat::format($seconds));
    }

    public static function cases(): array
    {
        return [
            '1m 48s'  => [108.0, '1m 48s'],
            '0m 42s'  => [42.0, '0m 42s'],
            '2m 0s'   => [120.0, '2m 0s'],
            'rounds'  => [108.7, '1m 48s'],
            'zero'    => [0.0, '0m 0s'],
        ];
    }
}
