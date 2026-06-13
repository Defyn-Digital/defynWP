import { describe, it, expect, vi } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { useBulkUpdatePlugins } from '@/lib/mutations/useBulkUpdatePlugins';
import React from 'react';

function makeWrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('useBulkUpdatePlugins', () => {
  it('postsToBulkUpdateEndpointWithCorrectBody', async () => {
    let capturedBody: unknown = null;
    server.use(
      http.post('*/wp-json/defyn/v1/overview/bulk-update-plugins', async ({ request }) => {
        capturedBody = await request.json();
        return HttpResponse.json(
          {
            job_id: 42,
            scheduled_count: 2,
            skipped_count: 0,
            scheduled_pairs: [
              { site_id: 1, slug: 'akismet' },
              { site_id: 1, slug: 'yoast' },
            ],
            skipped_pairs: [],
            scheduled_at: '2026-06-09 23:15:42',
          },
          { status: 202 },
        );
      }),
    );

    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const { result } = renderHook(() => useBulkUpdatePlugins(), { wrapper: makeWrapper(qc) });
    result.current.mutate({
      updates: [
        { site_id: 1, slug: 'akismet' },
        { site_id: 1, slug: 'yoast' },
      ],
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(capturedBody).toEqual({
      updates: [
        { site_id: 1, slug: 'akismet' },
        { site_id: 1, slug: 'yoast' },
      ],
    });
    expect(result.current.data?.scheduled_count).toBe(2);
  });

  it('invalidatesOverviewAndPendingQueriesOnSuccessButNotSites', async () => {
    server.use(
      http.post('*/wp-json/defyn/v1/overview/bulk-update-plugins', () =>
        HttpResponse.json(
          {
            job_id: 42,
            scheduled_count: 1,
            skipped_count: 0,
            scheduled_pairs: [{ site_id: 1, slug: 'akismet' }],
            skipped_pairs: [],
            scheduled_at: '2026-06-09 23:15:42',
          },
          { status: 202 },
        ),
      ),
    );

    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const invalidateSpy = vi.spyOn(qc, 'invalidateQueries');

    const { result } = renderHook(() => useBulkUpdatePlugins(), { wrapper: makeWrapper(qc) });
    result.current.mutate({ updates: [{ site_id: 1, slug: 'akismet' }] });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    await waitFor(() => {
      expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['overview'] });
    });
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['pendingPluginUpdates'] });

    // Plan-bug trap #11 — must NOT invalidate sites.
    const sitesCall = invalidateSpy.mock.calls.find(
      ([arg]) =>
        Array.isArray((arg as { queryKey?: unknown }).queryKey) &&
        (arg as { queryKey: unknown[] }).queryKey[0] === 'sites',
    );
    expect(sitesCall).toBeUndefined();
  });
});
