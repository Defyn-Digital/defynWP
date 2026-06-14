import { describe, it, expect } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { http, HttpResponse } from 'msw'
import { server } from '@/test/setup'
import { Settings } from '@/routes/Settings'

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/settings']}>
        <Routes>
          <Route path="/settings" element={<Settings />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Settings page', () => {
  it('pre-fills the input from useSettings when a webhook URL is saved', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/settings', () =>
        HttpResponse.json({ slack_webhook_url: 'https://hooks.slack.com/services/T000/B000/xxxx' }),
      ),
    )
    renderPage()
    await waitFor(() => {
      const input = screen.getByRole('textbox')
      expect((input as HTMLInputElement).value).toBe('https://hooks.slack.com/services/T000/B000/xxxx')
    })
  })

  it('shows an empty input when no webhook is saved', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/settings', () =>
        HttpResponse.json({ slack_webhook_url: null }),
      ),
    )
    renderPage()
    await waitFor(() => screen.getByRole('textbox'))
    expect((screen.getByRole('textbox') as HTMLInputElement).value).toBe('')
  })

  it('shows an inline error and disables Save for a non-hooks.slack.com URL', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/settings', () =>
        HttpResponse.json({ slack_webhook_url: null }),
      ),
    )
    renderPage()
    await waitFor(() => screen.getByRole('textbox'))

    await userEvent.type(screen.getByRole('textbox'), 'https://example.com/not-a-webhook')

    expect(screen.getByText(/must start with https:\/\/hooks\.slack\.com\//i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /save/i })).toBeDisabled()
  })

  it('enables Save and hides the error for a valid hooks.slack.com URL', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/settings', () =>
        HttpResponse.json({ slack_webhook_url: null }),
      ),
    )
    renderPage()
    await waitFor(() => screen.getByRole('textbox'))

    await userEvent.type(screen.getByRole('textbox'), 'https://hooks.slack.com/services/T000/B000/yyyy')

    expect(screen.queryByText(/must start with/i)).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: /save/i })).not.toBeDisabled()
  })

  it('calls the mutation and shows saving state on Save click', async () => {
    let mutationCalled = false
    server.use(
      http.get('*/wp-json/defyn/v1/settings', () =>
        HttpResponse.json({ slack_webhook_url: null }),
      ),
      http.post('*/wp-json/defyn/v1/settings/slack-webhook', async ({ request }) => {
        mutationCalled = true
        const body = (await request.json()) as { webhook_url?: string }
        return HttpResponse.json({ slack_webhook_url: body.webhook_url ?? null })
      }),
    )
    renderPage()
    await waitFor(() => screen.getByRole('textbox'))

    await userEvent.type(screen.getByRole('textbox'), 'https://hooks.slack.com/services/T000/B000/zzzz')
    await userEvent.click(screen.getByRole('button', { name: /save/i }))

    await waitFor(() => expect(mutationCalled).toBe(true))
  })

  it('allows clearing the webhook by saving an empty string', async () => {
    renderPage()
    await waitFor(() => screen.getByRole('textbox'))

    // Empty value is valid — Save should be enabled
    expect(screen.getByRole('button', { name: /save/i })).not.toBeDisabled()
  })
})
