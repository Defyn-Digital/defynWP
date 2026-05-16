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
      last_error: null,
      created_at: '2026-05-11 00:00:00',
    });
    const { result } = renderHook(() => useSites(), { wrapper });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.sites).toHaveLength(1);
    expect(result.current.data?.sites[0].url).toBe('https://a.test');
  });
});
