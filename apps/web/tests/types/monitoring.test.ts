import { describe, it, expect } from 'vitest';
import { monitoringSchema } from '@/types/api';

const valid = {
  summary: { total: 2, up: 1, down: 1, fleet_uptime_30d: 99.71, slowest_ms: 910 },
  sites: [
    {
      site_id: 2, label: 'SmartCoding', url: 'https://x.test', status: 'offline',
      last_response_time_ms: null, last_contact_at: '2026-06-14 03:11:00',
      uptime_7d: 97.1, uptime_30d: 99.4, open_incident_started_at: '2026-06-14 03:23:00',
    },
  ],
  generated_at: '2026-06-14 03:35:00',
};

describe('monitoringSchema', () => {
  it('parses a full payload', () => {
    expect(monitoringSchema.parse(valid).summary.slowest_ms).toBe(910);
  });
  it('accepts null aggregates and null latency', () => {
    const empty = { summary: { total: 0, up: 0, down: 0, fleet_uptime_30d: null, slowest_ms: null }, sites: [], generated_at: 'x' };
    expect(monitoringSchema.parse(empty).sites).toHaveLength(0);
  });
});
