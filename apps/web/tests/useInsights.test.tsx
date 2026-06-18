import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it } from 'vitest';
import type { ReactNode } from 'react';
import { useInsights } from '@/lib/queries/useInsights';

function wrap() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('useInsights', () => {
  it('returns the parsed fleet payload', async () => {
    const { result } = renderHook(() => useInsights(), { wrapper: wrap() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.performance.summary.total_sites).toBe(3);
    expect(result.current.data?.analytics.summary.connected).toBe(1);
  });
});
