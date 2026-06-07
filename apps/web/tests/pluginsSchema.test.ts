import { describe, expect, it } from 'vitest';
import {
  pluginSchema,
  sitePluginsListResponseSchema,
} from '@/types/api/plugins';

describe('pluginSchema', () => {
  it('accepts a plugin with an update available', () => {
    const parsed = pluginSchema.parse({
      slug: 'a.php',
      name: 'A',
      version: '1.0',
      update_available: true,
      update_version: '1.1',
      update_state: 'idle',
      last_update_error: null,
      last_update_attempt_at: null,
      tested_up_to: null,
    });
    expect(parsed.update_available).toBe(true);
    expect(parsed.update_version).toBe('1.1');
  });

  it('accepts version=null and update_version=null', () => {
    const parsed = pluginSchema.parse({
      slug: 'a.php',
      name: 'A',
      version: null,
      update_available: false,
      update_version: null,
      update_state: 'idle',
      last_update_error: null,
      last_update_attempt_at: null,
      tested_up_to: null,
    });
    expect(parsed.version).toBeNull();
  });
});

describe('sitePluginsListResponseSchema', () => {
  it('parses an empty list', () => {
    const parsed = sitePluginsListResponseSchema.parse({
      plugins: [],
      total: 0,
      last_synced_at: null,
    });
    expect(parsed.total).toBe(0);
  });

  it('parses a populated list', () => {
    const parsed = sitePluginsListResponseSchema.parse({
      plugins: [
        { slug: 'a.php', name: 'A', version: '1', update_available: false, update_version: null, update_state: 'idle', last_update_error: null, last_update_attempt_at: null, tested_up_to: null },
      ],
      total: 1,
      last_synced_at: '2026-06-04 11:30:00',
    });
    expect(parsed.plugins).toHaveLength(1);
    expect(parsed.last_synced_at).toBe('2026-06-04 11:30:00');
  });
});
