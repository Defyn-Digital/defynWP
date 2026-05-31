import { describe, it, expect } from 'vitest';
import { siteSchema, createSiteSchema, sitesListSchema } from '@/types/api';

describe('siteSchema', () => {
  it('parses a fully-populated site row from the backend', () => {
    const parsed = siteSchema.parse({
      id: 1,
      url: 'https://example.test',
      label: 'Site',
      status: 'active',
      last_contact_at: '2026-05-11 00:00:00',
      last_sync_at: '2026-05-11 00:00:00',
      last_error: null,
      created_at: '2026-05-11 00:00:00',
      wp_version: '6.9.4',
      php_version: '8.2.27',
      active_theme: { name: 'Twenty Twenty-Four', version: '1.0', parent: null },
      plugin_counts: { installed: 10, active: 5 },
      theme_counts: { installed: 2, active: 1 },
      ssl_status: 'enabled',
      ssl_expires_at: '2027-01-01T00:00:00Z',
    });
    expect(parsed.status).toBe('active');
  });

  it('accepts pending status without last_contact_at', () => {
    const parsed = siteSchema.parse({
      id: 1,
      url: 'https://example.test',
      label: '',
      status: 'pending',
      last_contact_at: null,
      last_sync_at: null,
      last_error: null,
      created_at: '2026-05-11 00:00:00',
      wp_version: null,
      php_version: null,
      active_theme: null,
      plugin_counts: null,
      theme_counts: null,
      ssl_status: null,
      ssl_expires_at: null,
    });
    expect(parsed.last_contact_at).toBeNull();
  });

  it('accepts offline status (F6 health-check)', () => {
    const parsed = siteSchema.parse({
      id: 1,
      url: 'https://example.test',
      label: '',
      status: 'offline',
      last_contact_at: '2026-05-30T00:00:00Z',
      last_sync_at: '2026-05-30T00:00:00Z',
      last_error: 'host unreachable',
      created_at: '2026-05-11 00:00:00',
      wp_version: null,
      php_version: null,
      active_theme: null,
      plugin_counts: null,
      theme_counts: null,
      ssl_status: null,
      ssl_expires_at: null,
    });
    expect(parsed.status).toBe('offline');
  });

  it('rejects unknown status values', () => {
    expect(() => siteSchema.parse({
      id: 1, url: 'https://example.test', label: '', status: 'mystery',
      last_contact_at: null, last_sync_at: null, last_error: null,
      created_at: '2026-05-11 00:00:00',
      wp_version: null, php_version: null, active_theme: null,
      plugin_counts: null, theme_counts: null,
      ssl_status: null, ssl_expires_at: null,
    })).toThrow();
  });
});

describe('createSiteSchema', () => {
  it('requires url, label, code', () => {
    const parsed = createSiteSchema.parse({
      url: 'https://example.test',
      label: 'Site',
      code: 'ABCDEFGH2345',
    });
    expect(parsed.url).toBe('https://example.test');
  });

  it('rejects http URLs (must be https)', () => {
    expect(() => createSiteSchema.parse({
      url: 'http://insecure.test', label: '', code: 'X',
    })).toThrow();
  });

  it('rejects code shorter than 12 chars', () => {
    expect(() => createSiteSchema.parse({
      url: 'https://example.test', label: '', code: 'SHORT',
    })).toThrow();
  });
});

describe('sitesListSchema', () => {
  it('parses an empty list', () => {
    const parsed = sitesListSchema.parse({ sites: [] });
    expect(parsed.sites).toHaveLength(0);
  });
});
