import { describe, it, expect } from 'vitest';
import { securitySchema, fleetSiteSecuritySchema, scanAllSecurityResponseSchema } from '@/types/api';

describe('security fleet Zod schemas', () => {
  it('parses a fleet payload with at-risk / clean / never-scanned sites', () => {
    const payload = {
      summary: { total_sites: 3, scanned_sites: 2, sites_at_risk: 1, critical: 1, high: 2, medium: 0, low: 1 },
      sites: [
        { site_id: 7, label: 'Acme', url: 'https://acme.test', last_security_scan_at: '2026-06-15 02:00:00',
          counts: { critical: 1, high: 2, medium: 0, low: 1, total: 4 } },
        { site_id: 8, label: 'Clean', url: 'https://b.test', last_security_scan_at: '2026-06-15 02:00:00',
          counts: { critical: 0, high: 0, medium: 0, low: 0, total: 0 } },
        { site_id: 9, label: 'Never', url: 'https://c.test', last_security_scan_at: null,
          counts: { critical: 0, high: 0, medium: 0, low: 0, total: 0 } },
      ],
      generated_at: '2026-06-15 03:00:00',
    };
    const parsed = securitySchema.parse(payload);
    expect(parsed.sites).toHaveLength(3);
    expect(parsed.sites[0].counts.critical).toBe(1);
    expect(parsed.sites[2].last_security_scan_at).toBeNull();
    expect(parsed.summary.sites_at_risk).toBe(1);
  });

  it('parses a single fleet-site row', () => {
    const row = { site_id: 1, label: 'X', url: 'https://x.test', last_security_scan_at: null,
      counts: { critical: 0, high: 0, medium: 0, low: 0, total: 0 } };
    expect(fleetSiteSecuritySchema.parse(row).site_id).toBe(1);
  });

  it('parses a scan-all response', () => {
    const r = scanAllSecurityResponseSchema.parse({ scheduled_count: 2, site_ids: [1, 2], scheduled_at: '2026-06-15 03:00:00' });
    expect(r.scheduled_count).toBe(2);
    expect(r.site_ids).toEqual([1, 2]);
  });
});
