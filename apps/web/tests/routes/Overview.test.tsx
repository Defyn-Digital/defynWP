import { describe, it, expect } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { http, HttpResponse } from 'msw'
import { server } from '@/test/setup'
import Overview from '@/routes/Overview'

function renderRoute() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/overview']}>
        <Routes>
          <Route path="/overview" element={<Overview />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Overview route', () => {
  it('renders all three widgets when MSW returns the canonical payload', async () => {
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
              url: 'https://smart.co',
              label: 'Smart',
              reasons: ['offline'],
              last_contact_at: null,
              ssl_expires_at: null,
            },
          ],
          recent_activity: [
            {
              id: 1,
              site_id: 1,
              site_label: 'Smart',
              event_type: 'plugin_update.succeeded',
              details: { slug: 'a' },
              created_at: '2026-06-07 11:30:00',
            },
          ],
          generated_at: '2026-06-07 11:30:00',
        }),
      ),
    )

    renderRoute()
    await waitFor(() => expect(screen.getByText('47')).toBeInTheDocument())
    expect(screen.getAllByText(/Smart/).length).toBeGreaterThan(0)
    expect(screen.getByText('plugin_update.succeeded')).toBeInTheDocument()
  })

  it('renders error state when MSW returns 500', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/overview', () =>
        HttpResponse.json(
          { error: { code: 'server.error', message: 'oops' } },
          { status: 500 },
        ),
      ),
    )

    renderRoute()
    await waitFor(() => expect(screen.getByText(/try again/i)).toBeInTheDocument())
  })
})
