import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import { useUpdateSitePlugin } from '@/lib/mutations/useUpdateSitePlugin';
import { useSitePlugins } from '@/lib/queries/useSitePlugins';
import { mockSitePlugins, resetMockSitePlugins } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function makeWrapper() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('useUpdateSitePlugin', () => {
  beforeEach(() => {
    resetMockSitePlugins();
    setAccessToken('fake');
    vi.useRealTimers();
    mockSitePlugins[1] = {
      plugins: [
        {
          slug: 'akismet',
          name: 'Akismet',
          version: '5.7',
          update_available: true,
          update_version: '5.8',
          update_state: 'idle',
          last_update_error: null,
          last_update_attempt_at: null,
          tested_up_to: null,
        },
      ],
      last_synced_at: '2026-06-06 00:00:00',
    };
  });

  it('fires POST, transitions row to idle, stops polling', async () => {
    const wrapper = makeWrapper();

    const list = renderHook(() => useSitePlugins(1), { wrapper });
    const mut = renderHook(() => useUpdateSitePlugin(1, 'akismet'), { wrapper });

    await waitFor(() => expect(list.result.current.data).toBeDefined());

    act(() => {
      mut.result.current.update();
    });

    await waitFor(() => expect(mut.result.current.isPolling).toBe(true));

    // MSW handler from Task 17 schedules queued -> updating @50ms -> idle @200ms.
    // The polling refetch (2s interval) observes the idle state on the first tick.
    await waitFor(() => expect(mut.result.current.isPolling).toBe(false), { timeout: 5_000 });

    const row = list.result.current.data?.plugins.find((p) => p.slug === 'akismet');
    expect(row?.version).toBe('5.8');
    expect(row?.update_available).toBe(false);
  });
});
