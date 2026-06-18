import { describe, it, expect } from 'vitest';
import { insightsSchema } from '@/types/api';

describe('insightsSchema', () => {
  it('parses a representative payload with null metric fields', () => {
    const payload = {
      performance: {
        summary: { total_sites: 2, measured: 1, avg_mobile: 42, avg_desktop: null, slow_sites: 1 },
        sites: [
          { site_id: 1, label: 'A', url: 'https://a', mobile_score: 42, desktop_score: 71, mobile_lcp_ms: 4600, fetched_at: '2026-06-01 00:00:00' },
          { site_id: 2, label: 'B', url: 'https://b', mobile_score: null, desktop_score: null, mobile_lcp_ms: null, fetched_at: null },
        ],
      },
      analytics: {
        summary: { total_sites: 2, connected: 1, total_sessions: 1240, total_users: 910 },
        sites: [
          { site_id: 1, label: 'A', url: 'https://a', ga4_property_id: '111', sessions: 1240, total_users: 910, screen_page_views: 3410, avg_session_duration: 72, period_start: '2026-05-01', period_end: '2026-05-31', fetched_at: '2026-06-01 00:00:00' },
          { site_id: 2, label: 'B', url: 'https://b', ga4_property_id: null, sessions: null, total_users: null, screen_page_views: null, avg_session_duration: null, period_start: null, period_end: null, fetched_at: null },
        ],
      },
      generated_at: '2026-06-18 00:00:00',
    };
    expect(() => insightsSchema.parse(payload)).not.toThrow();
  });
});
