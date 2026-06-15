import { renderHook, waitFor, act } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import { useScanAllSecurity } from '@/lib/mutations/useScanAllSecurity';

function wrap(client: QueryClient) {
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('useScanAllSecurity', () => {
  it('posts scan-all and invalidates the security query on success', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries');

    const { result } = renderHook(() => useScanAllSecurity(), { wrapper: wrap(client) });
    act(() => { result.current.mutate(); });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.scheduled_count).toBe(0); // MSW default
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['security'] });
  });
});
