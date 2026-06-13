import { describe, it, expect, vi } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useRetryItem } from '@/lib/mutations/useRetryItem';
import React from 'react';

function makeWrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('useRetryItem', () => {
  it('posts to /jobs/{id}/items/{itemId}/retry and parses the 202 response', async () => {
    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const { result } = renderHook(() => useRetryItem(), { wrapper: makeWrapper(qc) });

    result.current.mutate({ jobId: 42, itemId: 202 });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.item_id).toBe(202);
  });

  it('invalidates job, jobs and jobsCount but NOT sites (guardrail #10)', async () => {
    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const invalidateSpy = vi.spyOn(qc, 'invalidateQueries');
    const { result } = renderHook(() => useRetryItem(), { wrapper: makeWrapper(qc) });

    result.current.mutate({ jobId: 42, itemId: 202 });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['job', 42] });
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['jobs'] });
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['jobsCount'] });

    const sitesCall = invalidateSpy.mock.calls.find(
      ([arg]) =>
        Array.isArray((arg as { queryKey?: unknown }).queryKey) &&
        (arg as { queryKey: unknown[] }).queryKey[0] === 'sites',
    );
    expect(sitesCall).toBeUndefined();
  });
});
