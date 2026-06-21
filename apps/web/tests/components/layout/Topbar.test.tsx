import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Topbar } from '@/components/layout/Topbar';

describe('Topbar', () => {
  it('fires onOpenSidebar when the menu button is clicked', async () => {
    const u = userEvent.setup();
    const onOpenSidebar = vi.fn();
    render(<Topbar onOpenSidebar={onOpenSidebar} />);
    await u.click(screen.getByRole('button', { name: /open navigation/i }));
    expect(onOpenSidebar).toHaveBeenCalledTimes(1);
  });
});
