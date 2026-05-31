import { describe, it, expect, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import * as React from 'react';
import { useSites } from '@/lib/queries/useSites';
import { resetMockSites, mockSites } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('useSites', () => {
  beforeEach(() => {
    resetMockSites();
    setAccessToken('fake');
  });

  it('returns an empty list when the user has no sites', async () => {
    const { result } = renderHook(() => useSites(), { wrapper });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.sites).toEqual([]);
  });

  it('returns the user list when populated', async () => {
    mockSites.push({
      id: 1,
      url: 'https://a.test',
      label: '',
      status: 'active',
      last_contact_at: '2026-05-11 00:00:00',
      last_sync_at: '2026-05-11 00:00:00',
      last_error: null,
      created_at: '2026-05-11 00:00:00',
      wp_version: '6.9.4',
      php_version: '8.2.27',
      active_theme: { name: 'Twenty Twenty-Four', version: '1.0', parent: null },
      plugin_counts: { installed: 10, active: 5 },
      theme_counts: { installed: 2, active: 1 },
      ssl_status: 'enabled',
      ssl_expires_at: '2027-01-01T00:00:00Z',
    });
    const { result } = renderHook(() => useSites(), { wrapper });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.sites).toHaveLength(1);
    expect(result.current.data?.sites[0].url).toBe('https://a.test');
  });
});
