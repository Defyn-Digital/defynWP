import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it } from 'vitest';
import type { ReactNode } from 'react';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { useSecurity } from '@/lib/queries/useSecurity';

function wrap() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('useSecurity', () => {
  it('returns the parsed fleet payload', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/security', () =>
        HttpResponse.json({
          summary: { total_sites: 1, scanned_sites: 1, sites_at_risk: 1, critical: 1, high: 0, medium: 0, low: 0 },
          sites: [{ site_id: 7, label: 'Acme', url: 'https://acme.test', last_security_scan_at: '2026-06-15 02:00:00',
            counts: { critical: 1, high: 0, medium: 0, low: 0, total: 1 } }],
          generated_at: '2026-06-15 03:00:00',
        }),
      ),
    );
    const { result } = renderHook(() => useSecurity(), { wrapper: wrap() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.summary.total_sites).toBe(1);
    expect(result.current.data?.sites[0].label).toBe('Acme');
  });
});
