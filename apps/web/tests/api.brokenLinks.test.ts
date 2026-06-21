import { describe, it, expect } from 'vitest';
import { siteBrokenLinksSchema, reportBrokenLinksSchema, siteReportSchema } from '@/types/api';

const FULL_ROW = {
  url: 'https://x.test/dead',
  status_code: 404,
  severity: 'broken',
  reason: 'not_found',
  link_type: 'external',
  source_url: 'https://site.test/page',
  source_title: 'Home page',
  anchor_text: 'click here',
  first_detected_at: '2026-05-28 03:00:00',
  last_detected_at: '2026-05-30 03:00:00',
};

describe('siteBrokenLinksSchema', () => {
  it('accepts a full payload with one row', () => {
    const parsed = siteBrokenLinksSchema.parse({
      last_link_scan_at: '2026-05-30 03:00:00',
      counts: { broken: 1, warning: 0, total: 1, internal: 0, external: 1 },
      links: [FULL_ROW],
    });
    expect(parsed.counts.broken).toBe(1);
    expect(parsed.links).toHaveLength(1);
    expect(parsed.links[0].severity).toBe('broken');
    expect(parsed.links[0].reason).toBe('not_found');
    expect(parsed.links[0].link_type).toBe('external');
  });

  it('accepts an empty/never-scanned payload', () => {
    const parsed = siteBrokenLinksSchema.parse({
      last_link_scan_at: null,
      counts: { broken: 0, warning: 0, total: 0, internal: 0, external: 0 },
      links: [],
    });
    expect(parsed.last_link_scan_at).toBeNull();
    expect(parsed.links).toHaveLength(0);
  });
});

describe('reportBrokenLinksSchema', () => {
  it('accepts an issues payload', () => {
    const parsed = reportBrokenLinksSchema.parse({
      state: 'issues',
      last_scanned: '2026-05-30 03:00:00',
      counts: { broken: 1, warning: 1, total: 2, internal: 1, external: 1 },
      items: [
        { url: 'https://x.test/dead', status_code: 404, severity: 'broken', reason: 'not_found', link_type: 'external', source_url: 'https://site.test/page' },
        { url: 'https://api.test/down', status_code: 503, severity: 'warning', reason: 'server_error', link_type: 'external', source_url: 'https://site.test/page' },
      ],
    });
    expect(parsed.state).toBe('issues');
    expect(parsed.items).toHaveLength(2);
  });

  it('accepts a not_checked payload', () => {
    const parsed = reportBrokenLinksSchema.parse({
      state: 'not_checked',
      last_scanned: null,
      counts: { broken: 0, warning: 0, total: 0, internal: 0, external: 0 },
      items: [],
    });
    expect(parsed.state).toBe('not_checked');
  });
});

describe('siteReportSchema with broken_links', () => {
  it('parses the extended report fixture including broken_links', () => {
    const parsed = siteReportSchema.parse({
      site: { id: 1, label: 'SmartCoding', url: 'https://smartcoding.test', wp_version: '6.9.4', logo_url: null },
      period: { from: '2026-05-01', to: '2026-05-31' },
      overview: { updates_applied: 1, uptime_range_percent: 99.95, open_findings: 1, wp_version: '6.9.4' },
      updates: [],
      uptime: {
        range_percent: 99.95,
        last_24h_percent: 100,
        last_7d_percent: 99.9,
        last_30d_percent: 99.95,
        incidents: [],
      },
      security: {
        last_scan_at: null,
        open_findings: [],
        severity_counts: { critical: 0, high: 0, medium: 0, low: 0 },
        scans: [],
      },
      performance: { latest: null, history: [] },
      analytics: {
        state: 'not_connected',
        period: null,
        totals: null,
        top_pages: [],
        channels: [],
        history: [],
      },
      broken_links: {
        state: 'issues',
        last_scanned: '2026-05-30 03:00:00',
        counts: { broken: 1, warning: 1, total: 2, internal: 1, external: 1 },
        items: [
          { url: 'https://x.test/dead', status_code: 404, severity: 'broken', reason: 'not_found', link_type: 'external', source_url: 'https://site.test/page' },
          { url: 'https://api.test/down', status_code: 503, severity: 'warning', reason: 'server_error', link_type: 'external', source_url: 'https://site.test/page' },
        ],
      },
    });
    expect(parsed.broken_links.state).toBe('issues');
    expect(parsed.broken_links.counts.total).toBe(2);
    expect(parsed.broken_links.items).toHaveLength(2);
  });
});
