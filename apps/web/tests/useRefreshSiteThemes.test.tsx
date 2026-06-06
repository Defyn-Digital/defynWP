import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import { useRefreshSiteThemes } from '@/lib/mutations/useRefreshSiteThemes';
import { mockSiteThemes, resetMockSiteThemes } from '@/test/handlers';

function wrap() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('useRefreshSiteThemes', () => {
  beforeEach(() => {
    resetMockSiteThemes();
    vi.useRealTimers();
  });

  it('starts polling after a successful refresh and stops when last_synced_at advances', async () => {
    // Seed with a theme so GET handler returns a last_synced_at value
    mockSiteThemes[42] = [
      {
        slug: 'twentytwentyfive',
        name: 'Twenty Twenty-Five',
        version: '1.2',
        parent_slug: null,
        is_active: true,
        update_available: false,
        update_version: null,
        update_state: 'idle',
        last_update_error: null,
        last_update_attempt_at: null,
      },
    ];

    const { result } = renderHook(() => useRefreshSiteThemes(42), { wrapper: wrap() });

    act(() => {
      result.current.refresh();
    });

    await waitFor(() => expect(result.current.isPolling).toBe(true));

    // The MSW handler bumps last_synced_at on the in-memory store after 20ms;
    // the polling refetch picks it up and stops.
    await waitFor(() => expect(result.current.isPolling).toBe(false), { timeout: 5_000 });
  });
});
