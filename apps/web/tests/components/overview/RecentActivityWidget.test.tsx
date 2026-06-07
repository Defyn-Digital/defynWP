import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { RecentActivityWidget } from '@/components/overview/RecentActivityWidget'

const baseEvent = {
  id: 1,
  site_id: 1,
  site_label: 'SmartCoding',
  event_type: 'plugin_update.succeeded',
  details: { slug: 'akismet' },
  created_at: '2026-06-07 11:30:00',
}

describe('RecentActivityWidget', () => {
  it('renders events in the order they are passed (reverse chronological)', () => {
    const events = [
      { ...baseEvent, id: 1, created_at: '2026-06-07 11:30:00' },
      { ...baseEvent, id: 2, created_at: '2026-06-07 10:30:00' },
      { ...baseEvent, id: 3, created_at: '2026-06-07 09:30:00' },
    ]
    render(<RecentActivityWidget events={events} />, { wrapper: MemoryRouter })

    const rows = screen.getAllByTestId('activity-row')
    expect(rows).toHaveLength(3)
    expect(rows[0]).toHaveTextContent('11:30:00')
  })

  it('renders at most 25 events even when passed more', () => {
    const events = Array.from({ length: 40 }, (_, i) => ({
      ...baseEvent,
      id: i,
      created_at: `2026-06-07 ${String(i % 24).padStart(2, '0')}:00:00`,
    }))
    render(<RecentActivityWidget events={events} />, { wrapper: MemoryRouter })

    expect(screen.getAllByTestId('activity-row')).toHaveLength(25)
  })
})
