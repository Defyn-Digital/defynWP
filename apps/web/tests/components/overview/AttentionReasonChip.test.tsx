import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { AttentionReasonChip } from '@/components/overview/AttentionReasonChip'

describe('AttentionReasonChip', () => {
  it('renders red palette for offline and failed_update reasons', () => {
    const { rerender } = render(<AttentionReasonChip reason="offline" />)
    expect(screen.getByText(/offline/i).className).toMatch(/bg-red-100/)
    expect(screen.getByText(/offline/i).className).toMatch(/text-red-800/)

    rerender(<AttentionReasonChip reason="failed_update" />)
    expect(screen.getByText(/failed update/i).className).toMatch(/bg-red-100/)
  })

  it('renders amber palette for ssl_expiring and sync_stale reasons', () => {
    const { rerender } = render(<AttentionReasonChip reason="ssl_expiring" />)
    expect(screen.getByText(/ssl expiring/i).className).toMatch(/bg-amber-100/)
    expect(screen.getByText(/ssl expiring/i).className).toMatch(/text-amber-800/)

    rerender(<AttentionReasonChip reason="sync_stale" />)
    expect(screen.getByText(/sync stale/i).className).toMatch(/bg-amber-100/)
  })
})
