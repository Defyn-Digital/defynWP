import { describe, it, expect } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { usePendingPluginUpdates } from '@/lib/queries/usePendingPluginUpdates';
import React from 'react';

function makeWrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('usePendingPluginUpdates', () => {
  it('does NOT fetch when enabled=false (dialog closed)', async () => {
    let fetchCount = 0;
    server.use(
      http.get('*/wp-json/defyn/v1/overview/pending-plugin-updates', () => {
        fetchCount++;
        return HttpResponse.json({ pending_updates: [], generated_at: '2026-06-09 23:00:00' });
      }),
    );

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    renderHook(() => usePendingPluginUpdates(false), { wrapper: makeWrapper(qc) });

    // Wait briefly to confirm no fetch fired.
    await new Promise((r) => setTimeout(r, 50));
    expect(fetchCount).toBe(0);
  });

  it('fetches and parses when enabled=true (dialog open)', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/overview/pending-plugin-updates', () => {
        return HttpResponse.json({
          pending_updates: [
            {
              site_id: 1,
              site_label: 'SmartCoding',
              slug: 'akismet',
              plugin_name: 'Akismet Anti-Spam',
              current_version: '5.3',
              target_version: '5.3.1',
            },
          ],
          generated_at: '2026-06-09 23:00:00',
        });
      }),
    );

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => usePendingPluginUpdates(true), { wrapper: makeWrapper(qc) });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.pending_updates).toHaveLength(1);
    expect(result.current.data?.pending_updates[0].slug).toBe('akismet');
  });
});
