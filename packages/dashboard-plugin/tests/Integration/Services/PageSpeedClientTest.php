<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\PageSpeedClient;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class PageSpeedClientTest extends AbstractSchemaTestCase
{
    private function lighthouseJson(float $score, int $lcp, float $cls, int $inp, string $inpKey = 'interaction-to-next-paint'): string
    {
        return json_encode(['lighthouseResult' => [
            'categories' => ['performance' => ['score' => $score]],
            'audits' => [
                'largest-contentful-paint' => ['numericValue' => $lcp],
                'cumulative-layout-shift'  => ['numericValue' => $cls],
                $inpKey                    => ['numericValue' => $inp],
            ],
        ]]);
    }

    public function testParsesLighthouse(): void
    {
        $client = new PageSpeedClient(fn ($url, $args) => ['response' => ['code' => 200], 'body' => $this->lighthouseJson(0.82, 2100, 0.14, 180)]);
        $res = $client->fetch('https://acme.test', 'mobile');
        self::assertSame(82, $res['score']);
        self::assertSame(2100, $res['lcp_ms']);
        self::assertSame(0.14, $res['cls']);
        self::assertSame(180, $res['inp_ms']);
    }

    public function testFallsBackToExperimentalInp(): void
    {
        $client = new PageSpeedClient(fn ($url, $args) => ['response' => ['code' => 200], 'body' => $this->lighthouseJson(0.9, 800, 0.02, 90, 'experimental-interaction-to-next-paint')]);
        self::assertSame(90, $client->fetch('https://acme.test', 'desktop')['inp_ms']);
    }

    public function testNullOnHttpError(): void
    {
        $wpErr = new \WP_Error('http_request_failed', 'boom');
        self::assertNull((new PageSpeedClient(fn ($url, $args) => $wpErr))->fetch('https://acme.test', 'mobile'));
        self::assertNull((new PageSpeedClient(fn ($url, $args) => ['response' => ['code' => 500], 'body' => '']))->fetch('https://acme.test', 'mobile'));
        self::assertNull((new PageSpeedClient(fn ($url, $args) => ['response' => ['code' => 200], 'body' => 'not json']))->fetch('https://acme.test', 'mobile'));
    }
}
