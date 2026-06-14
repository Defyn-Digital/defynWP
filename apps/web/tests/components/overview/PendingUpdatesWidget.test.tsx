import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { PendingUpdatesWidget } from '@/components/overview/PendingUpdatesWidget'

function renderWithRouter() {
  render(
    <MemoryRouter initialEntries={['/overview']}>
      <Routes>
        <Route path="/overview" element={
          <PendingUpdatesWidget
            counts={{ plugins: 47, themes: 3, cores_minor: 1, cores_major: 0, sites_with_any_update: 9 }}
          />
        } />
        <Route path="/sites" element={<div data-testid="sites-page">sites</div>} />
      </Routes>
    </MemoryRouter>
  )
}

describe('PendingUpdatesWidget', () => {
  it('renders three count cards with correct numbers', () => {
    renderWithRouter()
    expect(screen.getByText('47')).toBeInTheDocument()
    expect(screen.getByText('3')).toBeInTheDocument()
  })

  it('plugin card links to /overview/plugins', () => {
    renderWithRouter()
    const pluginCard = screen.getByRole('link', { name: /plugin updates/i })
    expect(pluginCard).toHaveAttribute('href', '/overview/plugins')
  })

  it('theme card links to /overview/themes', () => {
    renderWithRouter()
    const themeCard = screen.getByRole('link', { name: /theme updates/i })
    expect(themeCard).toHaveAttribute('href', '/overview/themes')
  })

  it('core card links to /sites?filter=has-core-update', () => {
    renderWithRouter()
    const coreCard = screen.getByRole('link', { name: /wp core updates/i })
    expect(coreCard).toHaveAttribute('href', '/sites?filter=has-core-update')
  })
})
