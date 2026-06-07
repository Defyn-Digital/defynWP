import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, beforeEach } from 'vitest';
import type { ReactNode } from 'react';
import { useSiteThemes } from '@/lib/queries/useSiteThemes';
import { mockSiteThemes, resetMockSiteThemes } from '@/test/handlers';

function wrap() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('useSiteThemes', () => {
  beforeEach(() => {
    resetMockSiteThemes();
  });

  it('returns themes from the API', async () => {
    mockSiteThemes[42] = [
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
        tested_up_to: null,
      },
    ];

    const { result } = renderHook(() => useSiteThemes(42), { wrapper: wrap() });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.themes).toHaveLength(1);
    expect(result.current.data?.themes[0].slug).toBe('twentytwentyfive');
    expect(result.current.data?.themes[0].is_active).toBe(true);
  });

  it('returns refetchInterval 30s when any row is updating', async () => {
    mockSiteThemes[42] = [
      {
        slug: 'twentytwentyfive',
        name: 'Twenty Twenty-Five',
        version: '1.2',
        parent_slug: null,
        is_active: true,
        update_available: true,
        update_version: '1.3',
        update_state: 'updating',
        last_update_error: null,
        last_update_attempt_at: null,
        tested_up_to: null,
      },
    ];

    const { result } = renderHook(() => useSiteThemes(42), { wrapper: wrap() });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.themes[0].update_state).toBe('updating');
  });
});
