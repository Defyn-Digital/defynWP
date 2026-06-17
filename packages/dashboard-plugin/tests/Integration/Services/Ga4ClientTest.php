<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\Ga4Client;
use PHPUnit\Framework\TestCase;

final class Ga4ClientTest extends TestCase
{
    private function serviceAccountJson(): string
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pem);
        return json_encode([
            'client_email' => 'svc@proj.iam.gserviceaccount.com',
            'private_key'  => $pem,
            'token_uri'    => 'https://oauth2.googleapis.com/token',
        ]);
    }

    private function batchJson(): array
    {
        return ['reports' => [
            ['rows' => [['metricValues' => [['value'=>'12480'],['value'=>'9210'],['value'=>'31540'],['value'=>'108.5']]]]],
            ['rows' => [
                ['dimensionValues'=>[['value'=>'/'],['value'=>'Home']],'metricValues'=>[['value'=>'8420']]],
                ['dimensionValues'=>[['value'=>'/services'],['value'=>'Services']],'metricValues'=>[['value'=>'5110']]],
            ]],
            ['rows' => [
                ['dimensionValues'=>[['value'=>'Organic Search']],'metricValues'=>[['value'=>'5200']]],
                ['dimensionValues'=>[['value'=>'Direct']],'metricValues'=>[['value'=>'3800']]],
            ]],
        ]];
    }

    /** @param array<string,mixed> $body */
    private function wpResponse(int $status, array $body): array
    {
        return ['response' => ['code' => $status], 'body' => json_encode($body)];
    }

    public function testFetchReportNormalizesBatch(): void
    {
        $batch = $this->batchJson();
        $http = function (string $url, array $args) use ($batch) {
            if (str_contains($url, 'oauth2.googleapis.com')) {
                return $this->wpResponse(200, ['access_token' => 'tok-123']);
            }
            return $this->wpResponse(200, $batch);
        };
        $client = new Ga4Client($http, $this->serviceAccountJson());
        $out = $client->fetchReport('123456789', '2026-06-01', '2026-06-30');

        self::assertSame(12480, $out['sessions']);
        self::assertSame(9210, $out['users']);
        self::assertSame(31540, $out['pageviews']);
        self::assertSame(108.5, $out['avg_engagement']);
        self::assertCount(2, $out['top_pages']);
        self::assertSame('Home', $out['top_pages'][0]['title']);
        self::assertSame('Organic Search', $out['channels'][0]['channel']);
        self::assertSame(5200, $out['channels'][0]['sessions']);
    }

    public function testNullWhenNoServiceAccount(): void
    {
        $client = new Ga4Client(fn () => $this->wpResponse(200, []), null);
        self::assertNull($client->fetchReport('123', '2026-06-01', '2026-06-30'));
    }

    public function testNullWhenTokenExchangeFails(): void
    {
        $http = fn (string $url) => str_contains($url, 'oauth2')
            ? $this->wpResponse(401, ['error' => 'invalid_grant'])
            : $this->wpResponse(200, $this->batchJson());
        $client = new Ga4Client($http, $this->serviceAccountJson());
        self::assertNull($client->fetchReport('123', '2026-06-01', '2026-06-30'));
    }

    public function testNullWhenBatchGarbage(): void
    {
        $http = fn (string $url) => str_contains($url, 'oauth2')
            ? $this->wpResponse(200, ['access_token' => 'tok'])
            : $this->wpResponse(200, ['nonsense' => true]);
        $client = new Ga4Client($http, $this->serviceAccountJson());
        self::assertNull($client->fetchReport('123', '2026-06-01', '2026-06-30'));
    }
}
