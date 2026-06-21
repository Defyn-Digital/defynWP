<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\SiteLogoResolver;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SiteLogoResolverTest extends AbstractSchemaTestCase
{
    /** @var int[] site ids whose transients this test seeds */
    private array $seededIds = [101, 102, 103, 104, 105];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearTransients();
    }

    protected function tearDown(): void
    {
        $this->clearTransients();
        parent::tearDown();
    }

    private function clearTransients(): void
    {
        foreach ($this->seededIds as $id) {
            delete_transient('defyn_site_logo_' . $id);
        }
    }

    /** A fake fetcher returning a canned JSON body string, with a call counter. */
    private function fakeFetcher(string $jsonBody, int &$calls): callable
    {
        return static function (string $url) use ($jsonBody, &$calls): string {
            $calls++;
            return $jsonBody;
        };
    }

    public function testResolvesIconUrlFromRestIndexAndCachesIt(): void
    {
        $calls = 0;
        $body = '{"name":"Acme","site_icon_url":"https://acme.test/icon.png"}';
        $resolver = new SiteLogoResolver($this->fakeFetcher($body, $calls));

        $result = $resolver->resolve(101, 'https://acme.test');

        self::assertSame('https://acme.test/icon.png', $result);
        self::assertSame(1, $calls);
        self::assertSame('https://acme.test/icon.png', get_transient('defyn_site_logo_101'));
    }

    public function testEmptyIconUrlReturnsNullAndCachesEmptySentinel(): void
    {
        $calls = 0;
        $body = '{"name":"Acme","site_icon_url":""}';
        $resolver = new SiteLogoResolver($this->fakeFetcher($body, $calls));

        $result = $resolver->resolve(102, 'https://acme.test');

        self::assertNull($result);
        self::assertSame('', get_transient('defyn_site_logo_102')); // empty-string sentinel cached
    }

    public function testCachedEmptySentinelShortCircuitsWithoutFetching(): void
    {
        $calls = 0;
        set_transient('defyn_site_logo_103', '', DAY_IN_SECONDS); // pre-resolved-to-nothing
        $resolver = new SiteLogoResolver($this->fakeFetcher('{"site_icon_url":"https://acme.test/x.png"}', $calls));

        $result = $resolver->resolve(103, 'https://acme.test');

        self::assertNull($result);
        self::assertSame(0, $calls); // fetcher never invoked on a cache hit
    }

    public function testNonHttpsUrlReturnsNullWithoutFetching(): void
    {
        $calls = 0;
        $resolver = new SiteLogoResolver($this->fakeFetcher('{"site_icon_url":"https://acme.test/x.png"}', $calls));

        $result = $resolver->resolve(104, 'http://acme.test');

        self::assertNull($result);
        self::assertSame(0, $calls); // no fetch for non-https
    }

    public function testFetcherThrowsReturnsNullNeverThrows(): void
    {
        $resolver = new SiteLogoResolver(static function (string $url): string {
            throw new \RuntimeException('boom');
        });

        $result = $resolver->resolve(105, 'https://acme.test');

        self::assertNull($result);
        self::assertSame('', get_transient('defyn_site_logo_105')); // failure cached as empty sentinel
    }
}
