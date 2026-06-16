<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

/** Pure Core Web Vitals rating against Google's good/needs-improvement/poor thresholds. */
final class CoreWebVitals
{
    private const THRESHOLDS = [
        'lcp' => [2500.0, 4000.0],   // ms
        'cls' => [0.1, 0.25],        // unitless
        'inp' => [200.0, 500.0],     // ms
    ];

    public static function rate(string $metric, int|float|null $value): string
    {
        if ($value === null || !isset(self::THRESHOLDS[$metric])) {
            return 'unknown';
        }
        [$good, $ni] = self::THRESHOLDS[$metric];
        if ($value <= $good) {
            return 'good';
        }
        return $value <= $ni ? 'needs-improvement' : 'poor';
    }
}
