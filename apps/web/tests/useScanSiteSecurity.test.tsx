import { renderHook, waitFor, act } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it } from 'vitest';
import type { ReactNode } from 'react';
import { useScanSiteSecurity } from '@/lib/mutations/useScanSiteSecurity';

function wrap() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('useScanSiteSecurity', () => {
  it('posts the scan and begins polling on success', async () => {
    const { result } = renderHook(() => useScanSiteSecurity(7), { wrapper: wrap() });
    expect(result.current.isPolling).toBe(false);

    act(() => { result.current.scan(); });

    await waitFor(() => expect(result.current.isPolling).toBe(true));
  });
});
