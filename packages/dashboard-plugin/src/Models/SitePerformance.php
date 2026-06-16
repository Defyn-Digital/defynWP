<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Models;

/**
 * Read-only DTO for a row of wp_defyn_site_performance.
 *
 * All metric columns are nullable — a failed PageSpeed strategy stores NULLs
 * for that strategy while the other strategy's values remain intact.
 */
final class SitePerformance
{
    public function __construct(
        public readonly int    $id,
        public readonly int    $siteId,
        public readonly ?int   $mobileScore,
        public readonly ?int   $mobileLcpMs,
        public readonly ?float $mobileCls,
        public readonly ?int   $mobileInpMs,
        public readonly ?int   $desktopScore,
        public readonly ?int   $desktopLcpMs,
        public readonly ?float $desktopCls,
        public readonly ?int   $desktopInpMs,
        public readonly string $fetchedAt,
        public readonly string $createdAt,
    ) {}

    /** @param array<string, mixed> $row wpdb result row (all values come back as strings or null) */
    public static function fromRow(array $row): self
    {
        $int = static fn ($v): ?int   => $v === null ? null : (int)   $v;
        $flt = static fn ($v): ?float => $v === null ? null : (float) $v;

        return new self(
            id:           (int)    $row['id'],
            siteId:       (int)    $row['site_id'],
            mobileScore:  $int($row['mobile_score']   ?? null),
            mobileLcpMs:  $int($row['mobile_lcp_ms']  ?? null),
            mobileCls:    $flt($row['mobile_cls']      ?? null),
            mobileInpMs:  $int($row['mobile_inp_ms']  ?? null),
            desktopScore: $int($row['desktop_score']  ?? null),
            desktopLcpMs: $int($row['desktop_lcp_ms'] ?? null),
            desktopCls:   $flt($row['desktop_cls']    ?? null),
            desktopInpMs: $int($row['desktop_inp_ms'] ?? null),
            fetchedAt:    (string) $row['fetched_at'],
            createdAt:    (string) $row['created_at'],
        );
    }

    /** @return array<string, mixed> shape the SPA receives over the wire */
    public function toJson(): array
    {
        return [
            'id'             => $this->id,
            'site_id'        => $this->siteId,
            'mobile_score'   => $this->mobileScore,
            'mobile_lcp_ms'  => $this->mobileLcpMs,
            'mobile_cls'     => $this->mobileCls,
            'mobile_inp_ms'  => $this->mobileInpMs,
            'desktop_score'  => $this->desktopScore,
            'desktop_lcp_ms' => $this->desktopLcpMs,
            'desktop_cls'    => $this->desktopCls,
            'desktop_inp_ms' => $this->desktopInpMs,
            'fetched_at'     => $this->fetchedAt,
            'created_at'     => $this->createdAt,
        ];
    }
}
