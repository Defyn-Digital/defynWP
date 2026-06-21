<?php
declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Links;

use Defyn\Connector\Links\LinkChecker;

/**
 * @group integration
 */
class LinkCheckerTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        parent::tearDown();
    }

    private function stubHttp(int $code): void
    {
        add_filter('pre_http_request', fn() => ['response' => ['code' => $code], 'body' => ''], 10, 0);
    }

    public function testReturnsStatusForReachableUrl(): void
    {
        $this->stubHttp(404);
        self::assertSame(['status' => 404, 'transport_error' => false], (new LinkChecker())->check('https://x.test/a'));
    }

    public function testWpErrorIsTransportError(): void
    {
        add_filter('pre_http_request', fn() => new \WP_Error('http', 'down'), 10, 0);
        self::assertSame(['status' => null, 'transport_error' => true], (new LinkChecker())->check('https://x.test/a'));
    }

    public function testHealthy200(): void
    {
        $this->stubHttp(200);
        self::assertSame(['status' => 200, 'transport_error' => false], (new LinkChecker())->check('https://x.test/a'));
    }
}
