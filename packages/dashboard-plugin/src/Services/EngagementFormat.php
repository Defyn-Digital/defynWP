<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/** P6.2 — pure: average-engagement seconds → "Xm Ys". Mirrored by the TS helper. */
final class EngagementFormat
{
    public static function format(float $seconds): string
    {
        $total = (int) floor($seconds);
        return intdiv($total, 60) . 'm ' . ($total % 60) . 's';
    }
}
