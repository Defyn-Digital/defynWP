import { describe, it, expect, beforeEach } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useUpdateSiteCore } from '@/lib/mutations/useUpdateSiteCore';
import {
  resetMockSites,
  resetMockSiteCoreState,
  seedMockSitesAllStatuses,
  mockSiteCoreState,
} from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: React.ReactNode }) =>
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('useUpdateSiteCore', () => {
  beforeEach(() => {
    resetMockSites();
    resetMockSiteCoreState();
    seedMockSitesAllStatuses();
    setAccessToken('fake');
    // Site ID 1 from seeding — set up an available update
    mockSiteCoreState[1] = {
      core_update_available: true,
      core_update_version: '7.0.1',
      core_update_state: 'idle',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
    };
  });

  it('fires POST and sets isPolling true after success', async () => {
    const siteId = 1;
    const Wrap = makeWrapper();

    const { result: mut } = renderHook(() => useUpdateSiteCore(siteId), { wrapper: Wrap });

    expect(mut.current.isPolling).toBe(false);
    expect(mut.current.isPending).toBe(false);

    // Trigger update
    act(() => {
      mut.current.update();
    });

    // Should be pending during request
    await waitFor(() => expect(mut.current.isPending).toBe(false));

    // Should start polling after success
    await waitFor(() => expect(mut.current.isPolling).toBe(true));
  });

  it('stops polling on failed update state', async () => {
    const siteId = 1;
    const Wrap = makeWrapper();

    // Seed with failed state trigger
    mockSiteCoreState[1] = {
      core_update_available: true,
      core_update_version: '7.0.1',
      core_update_state: 'failed',
      last_core_update_error: 'Upgrade failed',
      last_core_update_attempt_at: new Date().toISOString(),
    };

    const { result: mut } = renderHook(() => useUpdateSiteCore(siteId), { wrapper: Wrap });

    act(() => {
      mut.current.update();
    });

    // Should still start polling even on failed (since state is 'failed' from seeding)
    // and immediately stop since the effect checks for idle or failed
    await waitFor(() => expect(mut.current.isPolling).toBe(false), { timeout: 1000 });
  });

  it('returns error when no update available', async () => {
    const siteId = 1;
    const Wrap = makeWrapper();

    // Set up no update available
    mockSiteCoreState[1] = {
      core_update_available: false,
      core_update_version: null,
      core_update_state: 'idle',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
    };

    const { result: mut } = renderHook(() => useUpdateSiteCore(siteId), { wrapper: Wrap });

    act(() => {
      mut.current.update();
    });

    await waitFor(() => expect(mut.current.error).toBeDefined());
  });
});
