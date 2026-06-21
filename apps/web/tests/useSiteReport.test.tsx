import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it } from 'vitest';
import type { ReactNode } from 'react';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { useSiteReport } from '@/lib/queries/useSiteReport';

function wrap() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('useSiteReport', () => {
  it('resolves to a parsed Report with all four sections populated', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/sites/:id/report', () => {
        return HttpResponse.json({
          data: {
            site: { id: 7, label: 'SmartCoding', url: 'https://smartcoding.test', wp_version: '6.9.4', logo_url: null },
            period: { from: '2026-05-01', to: '2026-05-31' },
            overview: {
              updates_applied: 1,
              uptime_range_percent: 99.95,
              open_findings: 1,
              wp_version: '6.9.4',
            },
            updates: [
              {
                type: 'plugin',
                slug: 'elementor',
                component_name: 'Elementor',
                previous_version: '3.18.0',
                new_version: '3.18.3',
                applied_at: '2026-05-12 04:00:00',
              },
            ],
            uptime: {
              range_percent: 99.95,
              last_24h_percent: 100,
              last_7d_percent: 99.9,
              last_30d_percent: 99.95,
              incidents: [
                {
                  started_at: '2026-05-10 02:00:00',
                  ended_at: '2026-05-10 02:15:00',
                  duration_seconds: 900,
                  reason: 'host unreachable',
                  ongoing: false,
                },
              ],
            },
            security: {
              last_scan_at: '2026-05-30 03:00:00',
              open_findings: [
                {
                  type: 'plugin',
                  slug: 'elementor',
                  component_name: 'Elementor',
                  installed_version: '3.18.0',
                  severity: 'high',
                  cvss_score: 7.5,
                  cve: 'CVE-2024-5678',
                  fixed_in: '3.18.3',
                  title: 'XSS',
                  source_id: 'src-ele',
                  dismissed: false,
                },
              ],
              severity_counts: { critical: 0, high: 1, medium: 0, low: 0 },
              scans: [
                {
                  scanned_at: '2026-05-30 03:00:00',
                  total: 1,
                  critical: 0,
                  high: 1,
                  medium: 0,
                  low: 0,
                },
              ],
            },
            performance: {
              latest: {
                fetched_at: '2026-05-30 03:00:00',
                mobile: { score: 82, lcp_ms: 2100, cls: 0.14, inp_ms: 180 },
                desktop: { score: 96, lcp_ms: 1200, cls: 0.02, inp_ms: 90 },
              },
              history: [
                { fetched_at: '2026-05-23 03:00:00', mobile_score: 78, desktop_score: 94 },
                { fetched_at: '2026-05-30 03:00:00', mobile_score: 82, desktop_score: 96 },
              ],
            },
            analytics: {
              state: 'ready',
              period: { start: '2026-05-01', end: '2026-05-31' },
              totals: { sessions: 1200, users: 900, pageviews: 3400, avg_engagement_seconds: 95 },
              top_pages: [{ path: '/', title: 'Home', views: 800 }],
              channels: [{ channel: 'Direct', sessions: 600 }],
            },
            broken_links: {
              state: 'not_checked',
              last_scanned: null,
              counts: { broken: 0, warning: 0, total: 0, internal: 0, external: 0 },
              items: [],
            },
          },
          error: null,
        });
      }),
    );

    const { result } = renderHook(
      () => useSiteReport(7, '2026-05-01', '2026-05-31'),
      { wrapper: wrap() },
    );
    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    const report = result.current.data!;
    expect(report.overview.updates_applied).toBe(1);
    expect(report.updates).toHaveLength(1);
    expect(report.updates[0].slug).toBe('elementor');
    expect(report.uptime.incidents).toHaveLength(1);
    expect(report.uptime.incidents[0].ongoing).toBe(false);
    expect(report.security.open_findings).toHaveLength(1);
    expect(report.security.open_findings[0].slug).toBe('elementor');
    expect(report.security.scans).toHaveLength(1);
  });

  it('is disabled until siteId, from and to are all provided', () => {
    const { result } = renderHook(
      () => useSiteReport(0, '', ''),
      { wrapper: wrap() },
    );
    expect(result.current.fetchStatus).toBe('idle');
    expect(result.current.isSuccess).toBe(false);
  });
});
