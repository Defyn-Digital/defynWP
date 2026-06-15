import { describe, it, expect } from 'vitest';
import {
  vulnerabilitySchema,
  siteVulnerabilitiesSchema,
  overviewAttentionReasonSchema,
} from '@/types/api';

describe('security Zod schemas', () => {
  it('parses a site-vulnerabilities payload', () => {
    const payload = {
      scanned_at: '2026-06-15 03:00:00',
      vulnerabilities: [
        {
          type: 'plugin', slug: 'elementor', component_name: 'Elementor',
          installed_version: '3.18.0', severity: 'high', cvss_score: 7.5,
          cve: 'CVE-2024-5678', fixed_in: '3.18.3', title: 'XSS',
        },
      ],
    };
    const parsed = siteVulnerabilitiesSchema.parse(payload);
    expect(parsed.vulnerabilities[0].slug).toBe('elementor');
    expect(parsed.scanned_at).toBe('2026-06-15 03:00:00');
  });

  it('allows null scanned_at and nullable numeric fields', () => {
    const parsed = siteVulnerabilitiesSchema.parse({
      scanned_at: null,
      vulnerabilities: [
        { type: 'core', slug: 'wordpress', component_name: 'WordPress',
          installed_version: '6.4.1', severity: 'unknown', cvss_score: null,
          cve: null, fixed_in: null, title: null },
      ],
    });
    expect(parsed.scanned_at).toBeNull();
    expect(parsed.vulnerabilities[0].cvss_score).toBeNull();
  });

  it('accepts has_vulnerabilities as an attention reason', () => {
    expect(overviewAttentionReasonSchema.parse('has_vulnerabilities')).toBe('has_vulnerabilities');
  });

  it('rejects an unknown severity', () => {
    expect(() => vulnerabilitySchema.parse({
      type: 'plugin', slug: 'x', component_name: 'X', installed_version: '1.0',
      severity: 'apocalyptic', cvss_score: null, cve: null, fixed_in: null, title: null,
    })).toThrow();
  });
});
