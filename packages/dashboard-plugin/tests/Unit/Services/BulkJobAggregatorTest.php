<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Services;

use Defyn\Dashboard\Services\BulkJobAggregator;
use PHPUnit\Framework\TestCase;

/**
 * P2.9 — pure-function tests, no DB / no WP (guardrail #19).
 */
final class BulkJobAggregatorTest extends TestCase
{
    /** @param list<string> $states @return list<array{state: string}> */
    private static function items(array $states): array
    {
        return array_map(static fn(string $s) => ['state' => $s], $states);
    }

    public function testCountsByStateRollup(): void
    {
        $counts = BulkJobAggregator::countsByState(
            self::items(['queued', 'queued', 'started', 'succeeded', 'failed', 'cancelled'])
        );

        $this->assertSame(
            ['queued' => 2, 'started' => 1, 'succeeded' => 1, 'failed' => 1, 'cancelled' => 1],
            $counts
        );
    }

    public function testCountsByStateIgnoresUnknownStates(): void
    {
        $counts = BulkJobAggregator::countsByState(self::items(['queued', 'bogus']));

        $this->assertSame(1, $counts['queued']);
        $this->assertSame(1, array_sum($counts));
    }

    public function testDeriveJobStateQueuedWhenAllQueued(): void
    {
        $this->assertSame('queued', BulkJobAggregator::deriveJobState(self::items(['queued', 'queued'])));
    }

    public function testDeriveJobStateQueuedWhenNoItems(): void
    {
        // Defensive — jobs are only created with >= 1 item, but an empty array
        // must not divide-by-zero or mislabel.
        $this->assertSame('queued', BulkJobAggregator::deriveJobState([]));
    }

    public function testDeriveJobStateInProgressWhenAnyStarted(): void
    {
        $this->assertSame('in_progress', BulkJobAggregator::deriveJobState(self::items(['queued', 'started'])));
    }

    public function testDeriveJobStateInProgressWhenMixedTerminalAndNonTerminal(): void
    {
        $this->assertSame('in_progress', BulkJobAggregator::deriveJobState(self::items(['succeeded', 'queued'])));
    }

    public function testDeriveJobStateCompletedWhenAllSucceeded(): void
    {
        $this->assertSame('completed', BulkJobAggregator::deriveJobState(self::items(['succeeded', 'succeeded'])));
    }

    public function testDeriveJobStatePartialWhenAllTerminalButSomeFailedOrCancelled(): void
    {
        $this->assertSame('partial', BulkJobAggregator::deriveJobState(self::items(['succeeded', 'failed'])));
        $this->assertSame('partial', BulkJobAggregator::deriveJobState(self::items(['succeeded', 'cancelled'])));
        $this->assertSame('partial', BulkJobAggregator::deriveJobState(self::items(['failed', 'cancelled'])));
    }
}
