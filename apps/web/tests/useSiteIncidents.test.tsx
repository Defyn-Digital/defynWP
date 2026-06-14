import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, beforeEach } from 'vitest';
import type { ReactNode } from 'react';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { useSiteIncidents } from '@/lib/queries/useSiteIncidents';

function wrap() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('useSiteIncidents', () => {
  beforeEach(() => {
    // Reset to default empty handler (already registered in handlers.ts).
  });

  it('returns incidents from the API', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/sites/:id/incidents', () => {
        return HttpResponse.json({
          data: {
            incidents: [
              {
                id: 1,
                site_id: 7,
                started_at: '2026-06-14T01:00:00Z',
                ended_at: '2026-06-14T01:05:00Z',
                duration_seconds: 300,
                last_error: 'Connection refused',
                created_at: '2026-06-14T01:00:01Z',
              },
              {
                id: 2,
                site_id: 7,
                started_at: '2026-06-14T02:00:00Z',
                ended_at: null,
                duration_seconds: null,
                last_error: null,
                created_at: '2026-06-14T02:00:01Z',
              },
            ],
          },
          error: null,
        });
      }),
    );

    const { result } = renderHook(() => useSiteIncidents(7), { wrapper: wrap() });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toHaveLength(2);
    expect(result.current.data?.[0].id).toBe(1);
    expect(result.current.data?.[1].id).toBe(2);
    expect(result.current.data?.[1].ended_at).toBeNull();
  });

  it('returns an empty array when there are no incidents', async () => {
    // Default MSW handler already returns { data: { incidents: [] }, error: null }.
    const { result } = renderHook(() => useSiteIncidents(99), { wrapper: wrap() });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toEqual([]);
  });
});
