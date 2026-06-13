import { describe, it, expect, vi } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useCancelJob } from '@/lib/mutations/useCancelJob';
import React from 'react';

function makeWrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('useCancelJob', () => {
  it('posts to /jobs/{id}/cancel and parses the 200 response', async () => {
    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const { result } = renderHook(() => useCancelJob(), { wrapper: makeWrapper(qc) });

    result.current.mutate(42);

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.cancelled_count).toBe(1);
    expect(result.current.data?.still_running_count).toBe(0);
  });

  it('invalidates job, jobs and jobsCount but NOT sites (guardrail #10)', async () => {
    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const invalidateSpy = vi.spyOn(qc, 'invalidateQueries');
    const { result } = renderHook(() => useCancelJob(), { wrapper: makeWrapper(qc) });

    result.current.mutate(42);

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
