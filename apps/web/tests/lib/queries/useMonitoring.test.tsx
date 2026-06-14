import { describe, it, expect } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { http, HttpResponse } from 'msw'
import { server } from '@/test/setup'
import { useMonitoring } from '@/lib/queries/useMonitoring'
import React from 'react'

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
}

describe('useMonitoring', () => {
  it('fetches and parses the monitoring payload', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/monitoring', () =>
        HttpResponse.json({
          summary: { total: 1, up: 1, down: 0, fleet_uptime_30d: 100, slowest_ms: 200 },
          sites: [
            {
              site_id: 1,
              label: 'A',
              url: 'https://a.test',
              status: 'active',
              last_response_time_ms: 200,
              last_contact_at: '2026-06-14 03:00:00',
              uptime_7d: 100,
              uptime_30d: 100,
              open_incident_started_at: null,
            },
          ],
          generated_at: '2026-06-14 03:35:00',
        }),
      ),
    )

    const { result } = renderHook(() => useMonitoring(), { wrapper })
    await waitFor(() => expect(result.current.isSuccess).toBe(true))
    expect(result.current.data?.summary.total).toBe(1)
    expect(result.current.data?.sites[0].label).toBe('A')
  })

  it('returns empty sites array from the default MSW handler', async () => {
    // Default handler (registered in handlers.ts) returns summary with total: 0 + empty sites.
    const { result } = renderHook(() => useMonitoring(), { wrapper })
    await waitFor(() => expect(result.current.isSuccess).toBe(true))
    expect(result.current.data?.sites).toHaveLength(0)
    expect(result.current.data?.summary.total).toBe(0)
  })

  it('rejects malformed response via Zod', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/monitoring', () =>
        HttpResponse.json({ summary: 'not-an-object' }),
      ),
    )

    const { result } = renderHook(() => useMonitoring(), { wrapper })
    await waitFor(() => expect(result.current.isError).toBe(true))
  })
})
