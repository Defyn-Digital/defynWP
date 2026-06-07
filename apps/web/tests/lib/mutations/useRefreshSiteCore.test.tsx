import { describe, it, expect, beforeEach } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useRefreshSiteCore } from '@/lib/mutations/useRefreshSiteCore';
import { resetMockSites, resetMockSiteCoreState, seedMockSitesAllStatuses } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: React.ReactNode }) =>
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('useRefreshSiteCore', () => {
  beforeEach(() => {
    resetMockSites();
    resetMockSiteCoreState();
    seedMockSitesAllStatuses();
    setAccessToken('fake');
  });

  it('fires POST and sets isPolling true after success', async () => {
    const siteId = 1;
    const { result } = renderHook(() => useRefreshSiteCore(siteId), { wrapper: makeWrapper() });

    expect(result.current.isPolling).toBe(false);
    expect(result.current.isPending).toBe(false);

    act(() => {
      result.current.refresh();
    });

    await waitFor(() => expect(result.current.isPending).toBe(false));
    await waitFor(() => expect(result.current.isPolling).toBe(true));
  });

  it('stops polling after hard cap timeout', async () => {
    const siteId = 1;
    const { result } = renderHook(() => useRefreshSiteCore(siteId), { wrapper: makeWrapper() });

    act(() => {
      result.current.refresh();
    });

    await waitFor(() => expect(result.current.isPolling).toBe(true));
    // Hard cap is 60s, verify it times out
    await waitFor(() => expect(result.current.isPolling).toBe(false), {
      timeout: 70_000,
    });
  }, { timeout: 75_000 });

  it('returns error on failed mutation', async () => {
    const siteId = 999; // Non-existent site
    const { result } = renderHook(() => useRefreshSiteCore(siteId), { wrapper: makeWrapper() });

    act(() => {
      result.current.refresh();
    });

    await waitFor(() => expect(result.current.error).toBeDefined());
  });
});
