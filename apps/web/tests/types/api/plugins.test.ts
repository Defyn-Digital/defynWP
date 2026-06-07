import { describe, it, expect } from 'vitest';
import { pluginSchema, updateSitePluginResponseSchema } from '@/types/api/plugins';

describe('pluginSchema (P2.2 extension)', () => {
  it('accepts update_state, last_update_error, last_update_attempt_at', () => {
    const parsed = pluginSchema.parse({
      slug: 'akismet',
      name: 'Akismet',
      version: '5.7',
      update_available: true,
      update_version: '5.8',
      update_state: 'idle',
      last_update_error: null,
      last_update_attempt_at: null,
      tested_up_to: null,
    });
    expect(parsed.update_state).toBe('idle');
  });

  it('rejects an unknown update_state value', () => {
    expect(() =>
      pluginSchema.parse({
        slug: 'akismet',
        name: 'Akismet',
        version: '5.7',
        update_available: true,
        update_version: '5.8',
        update_state: 'mystery',
        last_update_error: null,
        last_update_attempt_at: null,
      }),
    ).toThrow();
  });

  it('rejects a payload missing update_state', () => {
    expect(() =>
      pluginSchema.parse({
        slug: 'akismet',
        name: 'Akismet',
        version: '5.7',
        update_available: true,
        update_version: '5.8',
        last_update_error: null,
        last_update_attempt_at: null,
      }),
    ).toThrow();
  });
});

describe('updateSitePluginResponseSchema', () => {
  it('accepts the 202 success shape', () => {
    const parsed = updateSitePluginResponseSchema.parse({
      scheduled: true,
      site_id: 1,
      slug: 'akismet',
    });
    expect(parsed.scheduled).toBe(true);
  });

  it('rejects scheduled: false (literal true required)', () => {
    expect(() =>
      updateSitePluginResponseSchema.parse({
        scheduled: false,
        site_id: 1,
        slug: 'akismet',
      }),
    ).toThrow();
  });
});
