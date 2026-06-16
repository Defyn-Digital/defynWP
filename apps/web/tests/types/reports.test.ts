import { describe, it, expect } from 'vitest';
import { reportSchema, reportStatusSchema, reportsResponseSchema } from '@/types/api';

describe('reportSchema', () => {
  it('parses a ready report', () => {
    const r = reportSchema.parse({
      id: 1, site_id: 2, title: 'Website Maintenance Report',
      range_from: '2026-05-01', range_to: '2026-05-31', status: 'ready',
      file_size: 1120, recipient_email: null, generated_at: '2026-06-01 02:00:00',
      sent_at: null, created_at: '2026-06-01 01:59:00',
    });
    expect(r.status).toBe('ready');
  });
  it('parses a generating report with null file_size', () => {
    const r = reportSchema.parse({
      id: 1, site_id: 2, title: 'T', range_from: '2026-05-01', range_to: '2026-05-31',
      status: 'generating', file_size: null, recipient_email: null,
      generated_at: null, sent_at: null, created_at: '2026-06-16 00:00:00',
    });
    expect(r.file_size).toBeNull();
  });
  it('rejects a bogus status', () => {
    expect(() => reportStatusSchema.parse('bogus')).toThrow();
  });
  it('parses the list envelope', () => {
    const e = reportsResponseSchema.parse({ data: { reports: [], total: 0, page: 1, per_page: 20 }, error: null });
    expect(e.data.total).toBe(0);
  });
});
