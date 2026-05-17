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
      last_error: null,
      created_at: '2026-05-11 00:00:00',
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
      last_error: null,
      created_at: '2026-05-11 00:00:00',
    });
    expect(parsed.last_contact_at).toBeNull();
  });

  it('rejects unknown status values', () => {
    expect(() => siteSchema.parse({
      id: 1, url: 'https://example.test', label: '', status: 'mystery',
      last_contact_at: null, last_error: null, created_at: '2026-05-11 00:00:00',
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
