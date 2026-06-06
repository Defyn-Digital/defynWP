import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, beforeEach } from 'vitest';
import type { ReactNode } from 'react';
import { useSitePlugins } from '@/lib/queries/useSitePlugins';
import { mockSitePlugins, resetMockSitePlugins } from '@/test/handlers';

function wrap() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('useSitePlugins', () => {
  beforeEach(() => {
    resetMockSitePlugins();
  });

  it('returns plugins from the API', async () => {
    mockSitePlugins[42] = {
      plugins: [
        { slug: 'a.php', name: 'A', version: '1', update_available: true, update_version: '2', update_state: 'idle', last_update_error: null, last_update_attempt_at: null },
      ],
      last_synced_at: '2026-06-04 11:00:00',
    };

    const { result } = renderHook(() => useSitePlugins(42), { wrapper: wrap() });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.total).toBe(1);
    expect(result.current.data?.plugins[0].slug).toBe('a.php');
  });
});
