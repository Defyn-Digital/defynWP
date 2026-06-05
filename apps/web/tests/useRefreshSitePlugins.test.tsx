import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import { useRefreshSitePlugins } from '@/lib/mutations/useRefreshSitePlugins';
import { mockSitePlugins, resetMockSitePlugins } from '@/test/handlers';

function wrap() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('useRefreshSitePlugins', () => {
  beforeEach(() => {
    resetMockSitePlugins();
    vi.useRealTimers();
  });

  it('starts polling after a successful refresh and stops when last_synced_at advances', async () => {
    mockSitePlugins[42] = { plugins: [], last_synced_at: null };

    const { result } = renderHook(() => useRefreshSitePlugins(42), { wrapper: wrap() });

    act(() => {
      result.current.refresh();
    });

    await waitFor(() => expect(result.current.isPolling).toBe(true));

    // The MSW handler bumps last_synced_at on the in-memory store after 20ms;
    // the polling refetch picks it up and stops.
    await waitFor(() => expect(result.current.isPolling).toBe(false), { timeout: 5_000 });
  });
});
