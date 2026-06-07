import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { SitesNeedingAttentionWidget } from '@/components/overview/SitesNeedingAttentionWidget'

describe('SitesNeedingAttentionWidget', () => {
  it('renders one row per site with chips for each reason', () => {
    render(
      <SitesNeedingAttentionWidget
        sites={[
          {
            site_id: 1,
            url: 'https://smartcoding.com.au',
            label: 'SmartCoding',
            reasons: ['offline', 'ssl_expiring'],
            last_contact_at: '2026-06-07 09:30:00',
            ssl_expires_at: '2026-06-25 00:00:00',
          },
        ]}
      />,
      { wrapper: MemoryRouter }
    )

    expect(screen.getByText(/SmartCoding/)).toBeInTheDocument()
    expect(screen.getByText(/offline/i)).toBeInTheDocument()
    expect(screen.getByText(/ssl expiring/i)).toBeInTheDocument()
  })

  it('renders an all-healthy message when the list is empty', () => {
    render(<SitesNeedingAttentionWidget sites={[]} />, { wrapper: MemoryRouter })
    expect(screen.getByText(/all sites healthy/i)).toBeInTheDocument()
  })

  it('row links navigate to /sites/{id}', () => {
    render(
      <SitesNeedingAttentionWidget
        sites={[
          {
            site_id: 42,
            url: 'https://acme.io',
            label: 'Acme',
            reasons: ['failed_update'],
            last_contact_at: null,
            ssl_expires_at: null,
          },
        ]}
      />,
      { wrapper: MemoryRouter }
    )

    const link = screen.getByRole('link', { name: /Acme/ })
    expect(link).toHaveAttribute('href', '/sites/42')
  })
})
