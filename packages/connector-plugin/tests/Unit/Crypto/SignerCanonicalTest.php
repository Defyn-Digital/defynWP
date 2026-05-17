<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\Crypto;

use Defyn\Connector\Crypto\Signer;
use PHPUnit\Framework\TestCase;

final class SignerCanonicalTest extends TestCase
{
    public function testCanonicalFormatMatchesSpecSection_5_2(): void
    {
        $canonical = Signer::canonical('GET', '/defyn-connector/v1/status', '1716000000', 'nonce-xyz', '');
        $expected  = "GET\n/defyn-connector/v1/status\n1716000000\nnonce-xyz\n" . hash('sha256', '');

        $this->assertSame($expected, $canonical);
    }

    public function testMethodIsUppercased(): void
    {
        $canonical = Signer::canonical('get', '/x', '1', 'n', '');
        $this->assertStringStartsWith("GET\n", $canonical);
    }

    public function testBodyIsHashedNotIncludedRaw(): void
    {
        $body = '{"hello":"world"}';
        $canonical = Signer::canonical('POST', '/x', '1', 'n', $body);
        $this->assertStringEndsWith(hash('sha256', $body), $canonical);
        $this->assertStringNotContainsString($body, $canonical);
    }
}
