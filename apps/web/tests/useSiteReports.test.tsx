import { describe, it, expect } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useSiteReports, siteReportsPollInterval } from '@/lib/queries/useSiteReports';
import type { Report } from '@/types/api';
import React from 'react';

function makeWrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

const BASE_REPORT: Report = {
  id: 1,
  site_id: 7,
  title: 'Website Maintenance Report',
  range_from: '2026-05-01',
  range_to: '2026-05-31',
  status: 'ready',
  file_size: 1120,
  recipient_email: null,
  generated_at: '2026-06-01 02:00:00',
  sent_at: null,
  created_at: '2026-06-01 01:59:00',
};

/** The inner `.data` payload the hook stores (reportsResponseSchema.parse(...).data). */
function listData(...statuses: Report['status'][]) {
  return {
    reports: statuses.map((status, i) => ({ ...BASE_REPORT, id: i + 1, status })),
    total: statuses.length,
    page: 1,
    per_page: 20,
  };
}

describe('useSiteReports', () => {
  it('fetches and parses the report list (default MSW handler)', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useSiteReports(7), { wrapper: makeWrapper(qc) });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.reports).toHaveLength(1);
    expect(result.current.data?.reports[0].id).toBe(1);
    expect(result.current.data?.reports[0].status).toBe('ready');
    expect(result.current.data?.total).toBe(1);
  });

  it('siteReportsPollInterval returns 5s while any report is generating', () => {
    expect(siteReportsPollInterval(listData('generating'))).toBe(5_000);
    expect(siteReportsPollInterval(listData('ready', 'generating'))).toBe(5_000);
  });

  it('siteReportsPollInterval stops polling when all reports terminal', () => {
    expect(siteReportsPollInterval(listData('ready', 'sent', 'failed'))).toBe(false);
  });

  it('siteReportsPollInterval returns false for an empty list', () => {
    expect(siteReportsPollInterval(listData())).toBe(false);
    expect(siteReportsPollInterval(undefined)).toBe(false);
  });
});
