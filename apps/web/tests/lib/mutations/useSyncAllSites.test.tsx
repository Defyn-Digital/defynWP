import { describe, it, expect, vi } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { useSyncAllSites } from '@/lib/mutations/useSyncAllSites';
import React from 'react';

function makeWrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('useSyncAllSites', () => {
  it('POSTs to /overview/sync-all and parses the 202 envelope', async () => {
    let postedPath: string | null = null;
    server.use(
      http.post('*/wp-json/defyn/v1/overview/sync-all', ({ request }) => {
        postedPath = new URL(request.url).pathname;
        return HttpResponse.json(
          {
            scheduled_count: 3,
            site_ids: [1, 2, 3],
            scheduled_at: '2026-06-08 09:30:42',
          },
          { status: 202 },
        );
      }),
    );

    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const { result } = renderHook(() => useSyncAllSites(), { wrapper: makeWrapper(qc) });
    result.current.mutate();

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(postedPath).toMatch(/\/overview\/sync-all$/);
    expect(result.current.data).toEqual({
      scheduled_count: 3,
      site_ids: [1, 2, 3],
      scheduled_at: '2026-06-08 09:30:42',
    });
  });

  it('invalidates the overview query on success and does NOT invalidate sites', async () => {
    server.use(
      http.post('*/wp-json/defyn/v1/overview/sync-all', () =>
        HttpResponse.json(
          { scheduled_count: 0, site_ids: [], scheduled_at: '2026-06-08 09:30:42' },
          { status: 200 },
        ),
      ),
    );

    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const invalidateSpy = vi.spyOn(qc, 'invalidateQueries');

    const { result } = renderHook(() => useSyncAllSites(), { wrapper: makeWrapper(qc) });
    result.current.mutate();

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    await waitFor(() => {
      expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['overview'] });
    });
    // Plan-bug trap #11 — must NOT invalidate sites.
    const sitesCall = invalidateSpy.mock.calls.find(
      ([arg]) => Array.isArray((arg as { queryKey?: unknown }).queryKey)
        && (arg as { queryKey: unknown[] }).queryKey[0] === 'sites',
    );
    expect(sitesCall).toBeUndefined();
  });
});
