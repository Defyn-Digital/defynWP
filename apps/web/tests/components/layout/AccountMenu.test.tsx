import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AccountMenu } from '@/components/layout/AccountMenu';

const user = { id: 1, email: 'pradeep@defyn.com.au', display_name: 'Pradeep' };

describe('AccountMenu', () => {
  it('shows the display name and email', () => {
    render(<AccountMenu user={user} onSignOut={() => {}} />);
    expect(screen.getByText('Pradeep')).toBeInTheDocument();
    expect(screen.getByText('pradeep@defyn.com.au')).toBeInTheDocument();
  });

  it('calls onSignOut when Sign out is chosen', async () => {
    const u = userEvent.setup();
    const onSignOut = vi.fn();
    render(<AccountMenu user={user} onSignOut={onSignOut} />);
    await u.click(screen.getByRole('button', { name: /pradeep/i }));
    await u.click(await screen.findByText(/sign out/i));
    expect(onSignOut).toHaveBeenCalledTimes(1);
  });
});
