import { describe, it, expect } from 'vitest';
import { incidentSchema, overviewSchema } from '@/types/api';

describe('incident schemas', () => {
  it('parses an open incident (null ended/duration)', () => {
    const r = incidentSchema.parse({
      id: 1,
      site_id: 2,
      started_at: '2026-06-14 10:00:00',
      ended_at: null,
      duration_seconds: null,
      last_error: 'x',
      created_at: '2026-06-14 10:00:00',
    });
    expect(r.ended_at).toBeNull();
    expect(r.duration_seconds).toBeNull();
  });

  it('parses a closed incident', () => {
    const r = incidentSchema.parse({
      id: 1,
      site_id: 2,
      started_at: '2026-06-14 10:00:00',
      ended_at: '2026-06-14 10:35:00',
      duration_seconds: 2100,
      last_error: 'x',
      created_at: '2026-06-14 10:00:00',
    });
    expect(r.duration_seconds).toBe(2100);
  });

  it('overviewSchema parses open_incidents array', () => {
    const r = overviewSchema.parse({
      pending_updates: {
        plugins: 0,
        themes: 0,
        cores_minor: 0,
        cores_major: 0,
        sites_with_any_update: 0,
      },
      sites_needing_attention: [],
      recent_activity: [],
      total_sites: 5,
      generated_at: '2026-06-14 10:00:00',
      open_incidents: [
        { site_id: 2, site_label: 'AcmeBlog', started_at: '2026-06-14 10:00:00' },
      ],
    });
    expect(r.open_incidents).toHaveLength(1);
    expect(r.open_incidents[0].site_label).toBe('AcmeBlog');
  });
});
