<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Services;

use Defyn\Dashboard\Services\CoreWebVitals;
use PHPUnit\Framework\TestCase;

final class CoreWebVitalsTest extends TestCase
{
    public function testRate(): void
    {
        self::assertSame('good', CoreWebVitals::rate('lcp', 2000));
        self::assertSame('needs-improvement', CoreWebVitals::rate('lcp', 3000));
        self::assertSame('poor', CoreWebVitals::rate('lcp', 5000));
        self::assertSame('good', CoreWebVitals::rate('cls', 0.05));
        self::assertSame('needs-improvement', CoreWebVitals::rate('cls', 0.2));
        self::assertSame('poor', CoreWebVitals::rate('cls', 0.5));
        self::assertSame('good', CoreWebVitals::rate('inp', 150));
        self::assertSame('poor', CoreWebVitals::rate('inp', 600));
        self::assertSame('unknown', CoreWebVitals::rate('lcp', null));
    }
}
