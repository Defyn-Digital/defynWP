<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\Links;

use Defyn\Connector\Links\LinkExtractor;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
final class LinkExtractorTest extends TestCase
{
    public function testExtractsAbsoluteHttpLinks(): void {
        $out = (new LinkExtractor())->extract('<a href="https://example.com/a">A</a>', 'https://site.test');
        self::assertCount(1, $out);
        self::assertSame('https://example.com/a', $out[0]['url']);
        self::assertSame('A', $out[0]['anchor_text']);
    }
    public function testResolvesRootRelativeAgainstHome(): void {
        $out = (new LinkExtractor())->extract('<a href="/blog/x">x</a>', 'https://site.test');
        self::assertSame('https://site.test/blog/x', $out[0]['url']);
    }
    public function testResolvesProtocolRelativeUsingHomeScheme(): void {
        $out = (new LinkExtractor())->extract('<a href="//cdn.test/y">y</a>', 'https://site.test');
        self::assertSame('https://cdn.test/y', $out[0]['url']);
    }
    public function testStripsFragmentAndDedupes(): void {
        $out = (new LinkExtractor())->extract('<a href="https://x.test/a#one">1</a><a href="https://x.test/a#two">2</a>', 'https://site.test');
        self::assertCount(1, $out);
        self::assertSame('https://x.test/a', $out[0]['url']);
    }
    public function testSkipsMailtoTelJsAndPureFragment(): void {
        $html = '<a href="mailto:a@b.test">m</a><a href="tel:123">t</a><a href="javascript:void(0)">j</a><a href="#sec">f</a>';
        self::assertSame([], (new LinkExtractor())->extract($html, 'https://site.test'));
    }
    public function testEmptyContentReturnsEmpty(): void {
        self::assertSame([], (new LinkExtractor())->extract('   ', 'https://site.test'));
    }
}
