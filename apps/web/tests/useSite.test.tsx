import { describe, it, expect, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import * as React from 'react';
import { useSite } from '@/lib/queries/useSite';
import { resetMockSites, mockSites } from '@/test/handlers';
import { setAccessToken, ApiError } from '@/lib/apiClient';

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('useSite', () => {
  beforeEach(() => {
    resetMockSites();
    setAccessToken('fake');
  });

  it('returns the site when found', async () => {
    mockSites.push({
      id: 42,
      url: 'https://x.test',
      label: '',
      status: 'pending',
      last_contact_at: null,
      last_sync_at: null,
      last_error: null,
      created_at: '2026-05-11 00:00:00',
      wp_version: null,
      php_version: null,
      active_theme: null,
      plugin_counts: null,
      theme_counts: null,
      ssl_status: null,
      ssl_expires_at: null,
    });
    const { result } = renderHook(() => useSite(42), { wrapper });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.id).toBe(42);
  });

  it('throws ApiError with sites.not_found code on missing site', async () => {
    const { result } = renderHook(() => useSite(999), { wrapper });
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect((result.current.error as ApiError).code).toBe('sites.not_found');
  });
});
