import { describe, it, expect } from 'vitest';
import { reportAnalyticsSchema } from '@/types/api';

describe('reportAnalyticsSchema history', () => {
  it('parses a ready payload with a sessions history', () => {
    const parsed = reportAnalyticsSchema.parse({
      state: 'ready',
      period: { start: '2026-06-01', end: '2026-06-30' },
      totals: { sessions: 980, users: 670, pageviews: 2450, avg_engagement_seconds: 123 },
      top_pages: [],
      channels: [],
      history: [
        { period_start: '2026-05-01', sessions: 500 },
        { period_start: '2026-06-01', sessions: 980 },
      ],
    });
    expect(parsed.history).toHaveLength(2);
    expect(parsed.history[1].sessions).toBe(980);
  });

  it('defaults history to [] when absent (backward-compatible)', () => {
    const parsed = reportAnalyticsSchema.parse({
      state: 'not_connected',
      period: null,
      totals: null,
      top_pages: [],
      channels: [],
    });
    expect(parsed.history).toEqual([]);
  });
});
