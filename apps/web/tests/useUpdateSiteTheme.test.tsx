import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import { useUpdateSiteTheme } from '@/lib/mutations/useUpdateSiteTheme';
import { useSiteThemes } from '@/lib/queries/useSiteThemes';
import { mockSiteThemes, resetMockSiteThemes } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function makeWrapper() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('useUpdateSiteTheme', () => {
  beforeEach(() => {
    resetMockSiteThemes();
    setAccessToken('fake');
    vi.useRealTimers();
    mockSiteThemes[1] = [
      {
        slug: 'twentytwentyfive',
        name: 'Twenty Twenty-Five',
        version: '1.2',
        parent_slug: null,
        is_active: true,
        update_available: true,
        update_version: '1.3',
        update_state: 'idle',
        last_update_error: null,
        last_update_attempt_at: null,
      },
    ];
  });

  it('fires POST, transitions row to idle, stops polling', async () => {
    const wrapper = makeWrapper();

    const list = renderHook(() => useSiteThemes(1), { wrapper });
    const mut = renderHook(() => useUpdateSiteTheme(1, 'twentytwentyfive'), { wrapper });

    // Wait for initial query load
    await waitFor(() => {
      expect(list.result.current.isSuccess).toBe(true);
      expect(list.result.current.data?.themes).toBeDefined();
    });

    // Trigger the mutation
    act(() => {
      mut.result.current.update();
    });

    // Should start polling after mutation succeeds
    await waitFor(() => expect(mut.result.current.isPolling).toBe(true));

    // MSW handler schedules transitions: idle -> queued (immediate)
    // -> updating @50ms -> idle (with version bumped) @200ms.
    // The polling should observe idle and stop within 4 seconds.
    await waitFor(() => expect(mut.result.current.isPolling).toBe(false), { timeout: 4_000 });

    // Verify the row was actually updated
    const row = list.result.current.data?.themes.find((t) => t.slug === 'twentytwentyfive');
    expect(row?.version).toBe('1.3');
    expect(row?.update_available).toBe(false);
  });
});
