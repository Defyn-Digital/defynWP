import { describe, it, expect } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
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

// The page now also renders the Report-branding card, so scope these queries to
// the Slack webhook input and the Notifications card's Save button specifically.
function webhookInput(): HTMLInputElement {
  return screen.getByLabelText(/Slack webhook URL/i) as HTMLInputElement
}

function notificationsSaveButton(): HTMLElement {
  const heading = screen.getByRole('heading', { name: /Notifications/i })
  const card = heading.closest('div') as HTMLElement
  return within(card).getByRole('button', { name: /save/i })
}

describe('Settings page', () => {
  it('pre-fills the input from useSettings when a webhook URL is saved', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/settings', () =>
        HttpResponse.json({
          slack_webhook_url: 'https://hooks.slack.com/services/T000/B000/xxxx',
          report_branding: { agency_name: 'Defyn Digital', accent_color: '#26215C', logo_url: '' },
        }),
      ),
    )
    renderPage()
    await waitFor(() => {
      expect(webhookInput().value).toBe('https://hooks.slack.com/services/T000/B000/xxxx')
    })
  })

  it('shows an empty input when no webhook is saved', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/settings', () =>
        HttpResponse.json({
          slack_webhook_url: null,
          report_branding: { agency_name: 'Defyn Digital', accent_color: '#26215C', logo_url: '' },
        }),
      ),
    )
    renderPage()
    await waitFor(() => webhookInput())
    expect(webhookInput().value).toBe('')
  })

  it('shows an inline error and disables Save for a non-hooks.slack.com URL', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/settings', () =>
        HttpResponse.json({
          slack_webhook_url: null,
          report_branding: { agency_name: 'Defyn Digital', accent_color: '#26215C', logo_url: '' },
        }),
      ),
    )
    renderPage()
    await waitFor(() => webhookInput())

    await userEvent.type(webhookInput(), 'https://example.com/not-a-webhook')

    expect(screen.getByText(/must start with https:\/\/hooks\.slack\.com\//i)).toBeInTheDocument()
    expect(notificationsSaveButton()).toBeDisabled()
  })

  it('enables Save and hides the error for a valid hooks.slack.com URL', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/settings', () =>
        HttpResponse.json({
          slack_webhook_url: null,
          report_branding: { agency_name: 'Defyn Digital', accent_color: '#26215C', logo_url: '' },
        }),
      ),
    )
    renderPage()
    await waitFor(() => webhookInput())

    await userEvent.type(webhookInput(), 'https://hooks.slack.com/services/T000/B000/yyyy')

    expect(screen.queryByText(/must start with/i)).not.toBeInTheDocument()
    expect(notificationsSaveButton()).not.toBeDisabled()
  })

  it('calls the mutation and shows saving state on Save click', async () => {
    let mutationCalled = false
    server.use(
      http.get('*/wp-json/defyn/v1/settings', () =>
        HttpResponse.json({
          slack_webhook_url: null,
          report_branding: { agency_name: 'Defyn Digital', accent_color: '#26215C', logo_url: '' },
        }),
      ),
      http.post('*/wp-json/defyn/v1/settings/slack-webhook', async ({ request }) => {
        mutationCalled = true
        const body = (await request.json()) as { webhook_url?: string }
        return HttpResponse.json({
          slack_webhook_url: body.webhook_url ?? null,
          report_branding: { agency_name: 'Defyn Digital', accent_color: '#26215C', logo_url: '' },
        })
      }),
    )
    renderPage()
    await waitFor(() => webhookInput())

    await userEvent.type(webhookInput(), 'https://hooks.slack.com/services/T000/B000/zzzz')
    await userEvent.click(notificationsSaveButton())

    await waitFor(() => expect(mutationCalled).toBe(true))
  })

  it('allows clearing the webhook by saving an empty string', async () => {
    renderPage()
    await waitFor(() => webhookInput())

    // Empty value is valid — Save should be enabled
    expect(notificationsSaveButton()).not.toBeDisabled()
  })
})
