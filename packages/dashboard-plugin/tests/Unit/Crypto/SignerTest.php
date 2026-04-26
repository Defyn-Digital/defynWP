<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Crypto;

use Defyn\Dashboard\Crypto\Signer;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    public function testCanonicalProducesSpecFormat(): void
    {
        // Spec § 5.2: METHOD\nPATH\nTIMESTAMP\nNONCE\nsha256(BODY)
        $canonical = Signer::canonical('GET', '/wp-json/defyn-connector/v1/status', '1776494192', 'abc123', '');

        $expectedBodyHash = hash('sha256', '');
        self::assertSame(
            "GET\n/wp-json/defyn-connector/v1/status\n1776494192\nabc123\n{$expectedBodyHash}",
            $canonical
        );
    }

    public function testCanonicalUppercasesMethod(): void
    {
        $upper = Signer::canonical('GET', '/x', '1', 'n', '');
        $lower = Signer::canonical('get', '/x', '1', 'n', '');

        self::assertSame($upper, $lower, 'method must be normalized to uppercase');
    }

    public function testCanonicalHashesBodyContent(): void
    {
        $body = '{"hello":"world"}';
        $canonical = Signer::canonical('POST', '/x', '1', 'n', $body);

        self::assertStringEndsWith("\n" . hash('sha256', $body), $canonical);
    }

    public function testCanonicalIsDeterministic(): void
    {
        $a = Signer::canonical('POST', '/x', '1', 'n', 'body');
        $b = Signer::canonical('POST', '/x', '1', 'n', 'body');

        self::assertSame($a, $b);
    }
}
