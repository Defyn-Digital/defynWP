import { describe, it, expect } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useJobsCount } from '@/lib/queries/useJobsCount';
import React from 'react';

function makeWrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('useJobsCount', () => {
  it('returns the active-jobs total from the list endpoint (trap #32)', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useJobsCount(), { wrapper: makeWrapper(qc) });

    // Default MSW /jobs handler returns 1 job for status=active.
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toBe(1);
  });

  it('queries with status=active and per_page=1', async () => {
    const { server } = await import('@/test/setup');
    const { http, HttpResponse } = await import('msw');

    let capturedUrl = '';
    server.use(
      http.get('*/wp-json/defyn/v1/jobs', ({ request }) => {
        capturedUrl = request.url;
        return HttpResponse.json({
          jobs: [],
          total: 3,
          page: 1,
          per_page: 1,
          generated_at: '2026-06-09 21:30:00',
        });
      }),
    );

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useJobsCount(), { wrapper: makeWrapper(qc) });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toBe(3);
    expect(capturedUrl).toContain('status=active');
    expect(capturedUrl).toContain('per_page=1');
  });
});
