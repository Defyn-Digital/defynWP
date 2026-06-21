<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Services;

use Defyn\Dashboard\Services\LinkClassifier;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
final class LinkClassifierTest extends TestCase
{
    /** @dataProvider cases */
    public function testClassify(?int $status, bool $transport, string $severity, string $reason): void
    {
        self::assertSame(['severity' => $severity, 'reason' => $reason], LinkClassifier::classify($status, $transport));
    }

    public static function cases(): array
    {
        return [
            'transport error'   => [null, true,  'warning', 'unreachable'],
            'null no transport' => [null, false, 'warning', 'unreachable'],
            '404'               => [404, false, 'broken',  'not_found'],
            '410'               => [410, false, 'broken',  'not_found'],
            '500'               => [500, false, 'warning', 'server_error'],
            '503'               => [503, false, 'warning', 'server_error'],
            '403'               => [403, false, 'warning', 'blocked'],
            '401'               => [401, false, 'warning', 'blocked'],
            '429'               => [429, false, 'warning', 'blocked'],
            '400'               => [400, false, 'warning', 'client_error'],
            '418'               => [418, false, 'warning', 'client_error'],
        ];
    }
}
