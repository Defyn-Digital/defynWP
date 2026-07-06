<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\Incident;

/**
 * P5.1 — read-only maintenance-report aggregator. Pulls Overview + Updates + Uptime +
 * Security for one site over [fromUtc, toUtc] from existing data. No writes, no schema.
 */
final class ReportService
{
    public function __construct(
        private readonly ?SitesRepository $sites = null,
        private readonly ?ActivityLogRepository $activity = null,
        private readonly ?IncidentsRepository $incidents = null,
        private readonly ?SiteVulnerabilitiesRepository $findings = null,
        private readonly ?SitePluginsRepository $plugins = null,
        private readonly ?ThemesRepository $themes = null,
        private readonly ?SitePerformanceRepository $performance = null,
        private readonly ?SiteAnalyticsRepository $analytics = null,
        private readonly ?SiteLogoResolver $logoResolver = null,
        private readonly ?BrokenLinksRepository $brokenLinks = null,
    ) {}

    /** @return array<string,mixed> */
    public function compose(int $siteId, int $userId, string $fromUtc, string $toUtc): array
    {
        $sites     = $this->sites ?? new SitesRepository();
        $activity  = $this->activity ?? new ActivityLogRepository();
        $incidents = $this->incidents ?? new IncidentsRepository();
        $findings  = $this->findings ?? new SiteVulnerabilitiesRepository();
        $logos     = $this->logoResolver ?? new SiteLogoResolver();

        $site     = $sites->findByIdForUser($siteId, $userId); // controller already 404s on null
        $label    = $site?->label ?? '';
        $url      = $site?->url ?? '';
        $wpVer    = $site?->wpVersion ?? '';
        $lastScan = $site?->lastSecurityScanAt;
        $logoUrl  = $logos->resolve($siteId, $url);

        $updates  = $this->buildUpdates($siteId, $fromUtc, $toUtc, $activity);
        $uptime   = $this->buildUptime($siteId, $fromUtc, $toUtc, $incidents);
        $security = $this->buildSecurity($siteId, $fromUtc, $toUtc, $findings, $activity, $lastScan);
        $performance  = $this->buildPerformance($siteId, $fromUtc, $toUtc);
        $analytics    = $this->buildAnalytics($site, $fromUtc, $toUtc);
        $brokenLinks  = $this->buildBrokenLinks($siteId, $site);

        return [
            'site'   => ['id' => $siteId, 'label' => $label, 'url' => $url, 'wp_version' => $wpVer, 'logo_url' => $logoUrl],
            'period' => ['from' => substr($fromUtc, 0, 10), 'to' => substr($toUtc, 0, 10)],
            'overview' => [
                'updates_applied'      => count($updates),
                'uptime_range_percent' => $uptime['range_percent'],
                'open_findings'        => count($security['open_findings']),
                'wp_version'           => $wpVer,
            ],
            'updates'      => $updates,
            'uptime'       => $uptime,
            'security'     => $security,
            'performance'  => $performance,
            'analytics'    => $analytics,
            'broken_links' => $brokenLinks,
        ];
    }

    /**
     * P6.2 — GA4 analytics for the report's calendar month. Reads ONLY cached
     * snapshots (never calls GA4 — no-sync-fetch guardrail). States:
     *   not_connected — site has no ga4_property_id
     *   pending       — connected but no month-snapshot, or the range isn't a clean calendar month
     *   ready         — snapshot found for the report's calendar month
     * @return array<string,mixed>
     */
    private function buildAnalytics(?\Defyn\Dashboard\Models\Site $site, string $fromUtc, string $toUtc): array
    {
        $empty = ['period' => null, 'totals' => null, 'top_pages' => [], 'channels' => [], 'history' => []];

        if ($site === null || $site->ga4PropertyId === null || $site->ga4PropertyId === '') {
            return ['state' => 'not_connected'] + $empty;
        }

        $fromDate = substr($fromUtc, 0, 10);
        $toDate   = substr($toUtc, 0, 10);
        $monthStart = substr($fromDate, 0, 7) . '-01';
        $monthEnd   = gmdate('Y-m-t', strtotime($monthStart . ' UTC'));
        if ($fromDate !== $monthStart || $toDate !== $monthEnd) {
            return ['state' => 'pending'] + $empty;
        }

        $repo = $this->analytics ?? new SiteAnalyticsRepository();
        $snap = $repo->findForSiteAndMonth($site->id, $monthStart);
        if ($snap === null) {
            return ['state' => 'pending'] + $empty;
        }

        $history = [];
        foreach ($repo->findRecentForSite($site->id, 12) as $h) {
            $history[] = ['period_start' => $h->periodStart, 'sessions' => $h->sessions];
        }

        return [
            'state'  => 'ready',
            'period' => ['start' => $snap->periodStart, 'end' => $snap->periodEnd],
            'totals' => [
                'sessions'               => $snap->sessions,
                'users'                  => $snap->totalUsers,
                'pageviews'              => $snap->screenPageViews,
                'avg_engagement_seconds' => $snap->avgSessionDuration,
            ],
            'top_pages' => $snap->topPages,
            'channels'  => $snap->channels,
            'history'   => $history,
        ];
    }

    /**
     * P7.1 — reads cached broken-link data only (never calls the connector).
     * States:
     *   not_checked — last_link_scan_at is null (scan has never run)
     *   clean       — scanned but zero broken-link rows found
     *   issues      — scanned and at least one row exists
     *
     * @return array<string,mixed>
     */
    private function buildBrokenLinks(int $siteId, ?\Defyn\Dashboard\Models\Site $site): array
    {
        $repo   = $this->brokenLinks ?? new BrokenLinksRepository();
        $last   = $site?->lastLinkScanAt;
        $counts = $repo->countsForSite($siteId);
        $state  = $last === null ? 'not_checked' : (((int) ($counts['total'] ?? 0)) === 0 ? 'clean' : 'issues');
        $items  = [];
        if ($state === 'issues') {
            foreach ($repo->findTopForReport($siteId, 20) as $r) {
                $items[] = [
                    'url'         => $r['url'],
                    'status_code' => $r['status_code'] ?? null,
                    'severity'    => $r['severity'],
                    'reason'      => $r['reason'],
                    'link_type'   => $r['link_type'],
                    'source_url'  => $r['source_url'],
                ];
            }
        }
        return ['state' => $state, 'last_scanned' => $last, 'counts' => $counts, 'items' => $items];
    }

    /** @return array<string,mixed> */
    private function buildPerformance(int $siteId, string $fromUtc, string $toUtc): array
    {
        $repo   = $this->performance ?? new SitePerformanceRepository();
        $latest = $repo->latestForSite($siteId);
        $latestJson = $latest === null ? null : [
            'fetched_at' => $latest->fetchedAt,
            'mobile'  => ['score' => $latest->mobileScore,  'lcp_ms' => $latest->mobileLcpMs,  'cls' => $latest->mobileCls,  'inp_ms' => $latest->mobileInpMs],
            'desktop' => ['score' => $latest->desktopScore, 'lcp_ms' => $latest->desktopLcpMs, 'cls' => $latest->desktopCls, 'inp_ms' => $latest->desktopInpMs],
        ];
        $history = [];
        foreach ($repo->findForSiteInRange($siteId, $fromUtc, $toUtc) as $p) {
            $history[] = ['fetched_at' => $p->fetchedAt, 'mobile_score' => $p->mobileScore, 'desktop_score' => $p->desktopScore];
        }
        return ['latest' => $latestJson, 'history' => $history];
    }

    /** @return list<array<string,mixed>> */
    private function buildUpdates(int $siteId, string $fromUtc, string $toUtc, ActivityLogRepository $activity): array
    {
        $rows    = $activity->findUpdatesForSiteInRange($siteId, $fromUtc, $toUtc);
        $nameMap = $this->slugNameMap($siteId);

        $out = [];
        foreach ($rows as $r) {
            $type = match ($r['event_type']) {
                'plugin_update.succeeded' => 'plugin',
                'theme_update.succeeded'  => 'theme',
                default                   => 'core',
            };
            $slug = $type === 'core' ? 'wordpress' : (string) ($r['details']['slug'] ?? '');
            $name = $type === 'core' ? 'WordPress' : ($nameMap[$type][$slug] ?? ($slug !== '' ? $slug : 'Unknown'));
            $prev = (string) ($r['details']['previous_version'] ?? '');
            $new  = (string) ($r['details']['new_version'] ?? '');
            // Skip no-op / retry rows where the version did not actually change
            // (e.g. a re-fired job logging "7.4.7 -> 7.4.7"). These are not real
            // maintenance work and shouldn't inflate the client-facing report.
            if ($prev !== '' && $prev === $new) {
                continue;
            }
            $out[] = [
                'type'             => $type,
                'slug'             => $slug,
                'component_name'   => $name,
                'previous_version' => $prev,
                'new_version'      => $new,
                'applied_at'       => $r['created_at'],
            ];
        }
        return $out;
    }

    /** @return array{plugin:array<string,string>, theme:array<string,string>} */
    private function slugNameMap(int $siteId): array
    {
        $plugins = $this->plugins ?? new SitePluginsRepository();
        $themes  = $this->themes ?? new ThemesRepository();
        $map = ['plugin' => [], 'theme' => []];
        foreach ($plugins->findAllForSite($siteId) as $p) {
            $map['plugin'][$p->slug] = $p->name;
        }
        foreach ($themes->findAllForSite($siteId) as $t) {
            $map['theme'][$t->slug] = $t->name;
        }
        return $map;
    }

    /** @return array<string,mixed> */
    private function buildUptime(int $siteId, string $fromUtc, string $toUtc, IncidentsRepository $incidents): array
    {
        $now    = time();
        $fromTs = (int) strtotime($fromUtc . ' UTC');
        $toTs   = (int) strtotime($toUtc . ' UTC');
        $loadFrom = gmdate('Y-m-d H:i:s', min($fromTs, $now - 31 * 86400));
        $loadTo   = gmdate('Y-m-d H:i:s', max($toTs, $now));
        $all      = $incidents->findForSiteInRange($siteId, $loadFrom, $loadTo);

        $epoch = array_map(static fn (Incident $i): array => [
            'started' => (int) strtotime($i->startedAt . ' UTC'),
            'ended'   => $i->endedAt !== null ? (int) strtotime($i->endedAt . ' UTC') : null,
        ], $all);

        $history = [];
        foreach ($all as $i) {
            $sTs = (int) strtotime($i->startedAt . ' UTC');
            $eTs = $i->endedAt !== null ? (int) strtotime($i->endedAt . ' UTC') : $now;
            if ($sTs <= $toTs && $eTs >= $fromTs) {
                $history[] = [
                    'started_at'       => $i->startedAt,
                    'ended_at'         => $i->endedAt,
                    'duration_seconds' => $i->durationSeconds,
                    'reason'           => $i->lastError,
                    'ongoing'          => $i->endedAt === null,
                ];
            }
        }

        return [
            'range_percent'    => MonitoringService::uptimePercent($epoch, $fromTs, $toTs),
            'last_24h_percent' => MonitoringService::uptimePercent($epoch, $now - 86400, $now),
            'last_7d_percent'  => MonitoringService::uptimePercent($epoch, $now - 7 * 86400, $now),
            'last_30d_percent' => MonitoringService::uptimePercent($epoch, $now - 30 * 86400, $now),
            'incidents'        => $history,
        ];
    }

    /** @return array<string,mixed> */
    private function buildSecurity(int $siteId, string $fromUtc, string $toUtc, SiteVulnerabilitiesRepository $findings, ActivityLogRepository $activity, ?string $lastScan): array
    {
        $open = array_values(array_filter($findings->findForSite($siteId), static fn ($v) => !$v->dismissed));
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $openJson = [];
        foreach ($open as $v) {
            if (isset($counts[$v->severity])) {
                $counts[$v->severity]++;
            }
            $openJson[] = $v->toJson();
        }

        $scans = [];
        foreach ($activity->findSecurityScansForSiteInRange($siteId, $fromUtc, $toUtc) as $r) {
            $d = $r['details'];
            $scans[] = [
                'scanned_at' => $r['created_at'],
                'total'      => (int) ($d['total'] ?? 0),
                'critical'   => (int) ($d['critical'] ?? 0),
                'high'       => (int) ($d['high'] ?? 0),
                'medium'     => (int) ($d['medium'] ?? 0),
                'low'        => (int) ($d['low'] ?? 0),
            ];
        }

        return [
            'last_scan_at'    => $lastScan,
            'open_findings'   => $openJson,
            'severity_counts' => $counts,
            'scans'           => $scans,
        ];
    }
}
