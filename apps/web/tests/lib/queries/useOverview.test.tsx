import { describe, it, expect } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { http, HttpResponse } from 'msw'
import { server } from '@/test/setup'
import { useOverview } from '@/lib/queries/useOverview'
import React from 'react'

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
}

describe('useOverview', () => {
  it('validates response against zod schema and returns parsed data', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/overview', () =>
        HttpResponse.json({
          pending_updates: {
            plugins: 47,
            themes: 3,
            cores_minor: 1,
            cores_major: 0,
            sites_with_any_update: 9,
          },
          sites_needing_attention: [
            {
              site_id: 1,
              url: 'https://smartcoding.com.au',
              label: 'SmartCoding',
              reasons: ['offline'],
              last_contact_at: '2026-06-07 09:30:00',
              ssl_expires_at: null,
            },
          ],
          recent_activity: [],
          total_sites: 0,
          generated_at: '2026-06-07 11:30:00',
          open_incidents: [],
        })
      )
    )

    const { result } = renderHook(() => useOverview(), { wrapper })
    await waitFor(() => expect(result.current.isSuccess).toBe(true))
    expect(result.current.data?.pending_updates.plugins).toBe(47)
    expect(result.current.data?.sites_needing_attention[0].reasons).toContain('offline')
  })

  it('rejects malformed response via Zod', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/overview', () =>
        HttpResponse.json({ pending_updates: 'not-an-object' })
      )
    )

    const { result } = renderHook(() => useOverview(), { wrapper })
    await waitFor(() => expect(result.current.isError).toBe(true))
  })
})
