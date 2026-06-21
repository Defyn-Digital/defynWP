<?php
declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Links;

use Defyn\Connector\Links\BrokenLinkScanner;
use Defyn\Connector\Links\LinkChecker;
use Defyn\Connector\Links\LinkExtractor;

/**
 * @group integration
 */
class BrokenLinkScannerTest extends \WP_UnitTestCase
{
    public function testReportsOnlyUnhealthyLinksWithLinkType(): void
    {
        $this->factory->post->create(['post_status' => 'publish', 'post_content' =>
            '<a href="https://ok.test/a">ok</a><a href="https://dead.test/x">dead</a>']);
        $checker = new class extends LinkChecker {
            public function check(string $url): array
            {
                return str_contains($url, 'dead')
                    ? ['status' => 404, 'transport_error' => false]
                    : ['status' => 200, 'transport_error' => false];
            }
        };
        $out = (new BrokenLinkScanner(new LinkExtractor(), $checker))->scan();
        self::assertCount(1, $out['links']);
        self::assertSame('https://dead.test/x', $out['links'][0]['url']);
        self::assertSame(404, $out['links'][0]['status']);
        self::assertSame('external', $out['links'][0]['link_type']);
        self::assertGreaterThanOrEqual(1, $out['scanned_posts']);
        self::assertFalse($out['truncated']);
    }

    public function testInternalLinkTypedInternal(): void
    {
        $home = wp_parse_url(get_home_url(), PHP_URL_HOST);
        $this->factory->post->create(['post_status' => 'publish',
            'post_content' => '<a href="https://' . $home . '/missing">x</a>']);
        $checker = new class extends LinkChecker {
            public function check(string $url): array
            {
                return ['status' => 404, 'transport_error' => false];
            }
        };
        $out = (new BrokenLinkScanner(new LinkExtractor(), $checker))->scan();
        self::assertSame('internal', $out['links'][0]['link_type']);
    }
}
