import { describe, it, expect } from 'vitest';
import {
  themeSchema,
  siteThemesListResponseSchema,
  updateSiteThemeResponseSchema,
} from '@/types/api/themes';

describe('themeSchema', () => {
  it('accepts a standalone active theme with an update available', () => {
    const parsed = themeSchema.parse({
      slug: 'twentytwentyfive',
      name: 'Twenty Twenty-Five',
      version: '1.2',
      parent_slug: null,
      is_active: true,
      update_available: true,
      update_version: '1.3',
      update_state: 'idle',
      last_update_error: null,
      last_update_attempt_at: null,
    });
    expect(parsed.is_active).toBe(true);
    expect(parsed.parent_slug).toBeNull();
  });

  it('accepts a child theme with a parent_slug', () => {
    const parsed = themeSchema.parse({
      slug: 'astra-child',
      name: 'Astra Child',
      version: '1.0.0',
      parent_slug: 'astra',
      is_active: false,
      update_available: false,
      update_version: null,
      update_state: 'idle',
      last_update_error: null,
      last_update_attempt_at: null,
    });
    expect(parsed.parent_slug).toBe('astra');
  });

  it('rejects an unknown update_state value', () => {
    expect(() =>
      themeSchema.parse({
        slug: 'twentytwentyfive',
        name: 'Twenty Twenty-Five',
        version: '1.2',
        parent_slug: null,
        is_active: true,
        update_available: false,
        update_version: null,
        update_state: 'mystery',
        last_update_error: null,
        last_update_attempt_at: null,
      }),
    ).toThrow();
  });
});

describe('siteThemesListResponseSchema', () => {
  it('accepts the index response shape', () => {
    const parsed = siteThemesListResponseSchema.parse({
      themes: [],
      last_synced_at: null,
    });
    expect(parsed.themes).toEqual([]);
    expect(parsed.last_synced_at).toBeNull();
  });
});

describe('updateSiteThemeResponseSchema', () => {
  it('accepts the 202 success shape', () => {
    const parsed = updateSiteThemeResponseSchema.parse({
      scheduled: true,
      site_id: 1,
      slug: 'twentytwentyfive',
      update_state: 'queued',
    });
    expect(parsed.scheduled).toBe(true);
    expect(parsed.update_state).toBe('queued');
  });

  it('rejects scheduled: false (literal true required)', () => {
    expect(() =>
      updateSiteThemeResponseSchema.parse({
        scheduled: false,
        site_id: 1,
        slug: 'twentytwentyfive',
        update_state: 'queued',
      }),
    ).toThrow();
  });
});
