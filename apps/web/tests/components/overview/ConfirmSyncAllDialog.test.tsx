import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ConfirmSyncAllDialog } from '@/components/overview/ConfirmSyncAllDialog';

describe('ConfirmSyncAllDialog', () => {
  it('Cancel button has default focus when opened', () => {
    render(
      <ConfirmSyncAllDialog
        open
        totalSites={12}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    const cancel = screen.getByRole('button', { name: /^cancel$/i });
    expect(cancel).toHaveFocus();
  });

  it('primary button label includes the dynamic total_sites count', () => {
    render(
      <ConfirmSyncAllDialog
        open
        totalSites={12}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByRole('button', { name: /sync all 12 sites/i })).toBeInTheDocument();
  });
});
