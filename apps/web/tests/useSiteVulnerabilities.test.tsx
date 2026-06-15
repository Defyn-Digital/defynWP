import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it } from 'vitest';
import type { ReactNode } from 'react';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { useSiteVulnerabilities } from '@/lib/queries/useSiteVulnerabilities';

function wrap() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('useSiteVulnerabilities', () => {
  it('returns scanned_at + findings from the API', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/sites/:id/vulnerabilities', () => {
        return HttpResponse.json({
          data: {
            scanned_at: '2026-06-15 03:00:00',
            vulnerabilities: [
              { type: 'plugin', slug: 'elementor', component_name: 'Elementor',
                installed_version: '3.18.0', severity: 'high', cvss_score: 7.5,
                cve: 'CVE-2024-5678', fixed_in: '3.18.3', title: 'XSS',
                source_id: 'src-ele', dismissed: false },
            ],
          },
          error: null,
        });
      }),
    );

    const { result } = renderHook(() => useSiteVulnerabilities(7), { wrapper: wrap() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.scanned_at).toBe('2026-06-15 03:00:00');
    expect(result.current.data?.vulnerabilities).toHaveLength(1);
    expect(result.current.data?.vulnerabilities[0].slug).toBe('elementor');
  });
});
