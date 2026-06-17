<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Models;

/**
 * P6.2 — one (site, calendar-month) GA4 snapshot. Immutable. top_pages/channels
 * are decoded JSON arrays; metric counts are null-tolerant (zero-traffic months
 * still store a row).
 */
final class SiteAnalytics
{
    /**
     * @param list<array{path:string,title:string,views:int}> $topPages
     * @param list<array{channel:string,sessions:int}>        $channels
     */
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly ?int $sessions,
        public readonly ?int $totalUsers,
        public readonly ?int $screenPageViews,
        public readonly ?float $avgSessionDuration,
        public readonly array $topPages,
        public readonly array $channels,
        public readonly string $fetchedAt,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $int = static fn ($v): ?int => $v === null || $v === '' ? null : (int) $v;
        $flt = static fn ($v): ?float => $v === null || $v === '' ? null : (float) $v;
        $arr = static function ($v): array {
            if (!is_string($v) || $v === '') {
                return [];
            }
            $decoded = json_decode($v, true);
            return is_array($decoded) ? $decoded : [];
        };

        return new self(
            id: (int) $row['id'],
            siteId: (int) $row['site_id'],
            periodStart: (string) $row['period_start'],
            periodEnd: (string) $row['period_end'],
            sessions: $int($row['sessions'] ?? null),
            totalUsers: $int($row['total_users'] ?? null),
            screenPageViews: $int($row['screen_page_views'] ?? null),
            avgSessionDuration: $flt($row['avg_session_duration'] ?? null),
            topPages: $arr($row['top_pages'] ?? null),
            channels: $arr($row['channels'] ?? null),
            fetchedAt: (string) $row['fetched_at'],
        );
    }

    /** @return array<string,mixed> */
    public function toJson(): array
    {
        return [
            'id'                   => $this->id,
            'site_id'              => $this->siteId,
            'period_start'         => $this->periodStart,
            'period_end'           => $this->periodEnd,
            'sessions'             => $this->sessions,
            'total_users'          => $this->totalUsers,
            'screen_page_views'    => $this->screenPageViews,
            'avg_session_duration' => $this->avgSessionDuration,
            'top_pages'            => $this->topPages,
            'channels'             => $this->channels,
            'fetched_at'           => $this->fetchedAt,
        ];
    }
}
