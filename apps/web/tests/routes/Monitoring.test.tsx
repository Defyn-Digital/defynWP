import { describe, it, expect } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { http, HttpResponse } from 'msw'
import { server } from '@/test/setup'
import { Monitoring } from '@/routes/Monitoring'

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/monitoring']}>
        <Routes>
          <Route path="/monitoring" element={<Monitoring />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Monitoring page', () => {
  it('shows the empty state when no sites', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/monitoring', () =>
        HttpResponse.json({
          summary: { total: 0, up: 0, down: 0, fleet_uptime_30d: null, slowest_ms: null },
          sites: [],
          generated_at: '2026-06-14 03:35:00',
        }),
      ),
    )
    renderPage()
    await waitFor(() => expect(screen.getByText('No sites yet')).toBeInTheDocument())
  })

  it('renders the strip + table when sites exist', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/monitoring', () =>
        HttpResponse.json({
          summary: { total: 1, up: 1, down: 0, fleet_uptime_30d: 100, slowest_ms: 188 },
          sites: [
            {
              site_id: 4,
              label: 'Northwind',
              url: 'https://n.test',
              status: 'active',
              last_response_time_ms: 188,
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
    renderPage()
    await waitFor(() => expect(screen.getByText('Northwind')).toBeInTheDocument())
    // 188ms appears in both the MonitoringSummaryStrip KPI and the MonitoringTable latency cell
    expect(screen.getAllByText('188ms').length).toBeGreaterThanOrEqual(1)
  })
})
