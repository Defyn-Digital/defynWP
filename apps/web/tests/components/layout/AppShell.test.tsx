import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { AuthContext } from '@/lib/auth';
import { AppShell } from '@/components/layout/AppShell';

const authValue = {
  status: 'authenticated' as const,
  user: { id: 1, email: 'pradeep@defyn.com.au', display_name: 'Pradeep' },
  login: async () => {},
  loginWithGoogle: async () => {},
  logout: async () => {},
};

function renderShell() {
  return render(
    <AuthContext.Provider value={authValue}>
      <MemoryRouter initialEntries={['/overview']}>
        <AppShell><div>page-body</div></AppShell>
      </MemoryRouter>
    </AuthContext.Provider>,
  );
}

describe('AppShell', () => {
  it('renders the sidebar nav and the routed child', () => {
    renderShell();
    expect(screen.getByRole('link', { name: /sites/i })).toBeInTheDocument();
    expect(screen.getByText('page-body')).toBeInTheDocument();
  });

  it('opens the mobile sidebar sheet from the topbar menu button', async () => {
    const u = userEvent.setup();
    renderShell();
    // Before opening, the sheet content is not mounted; the desktop sidebar
    // has one Sites link. Opening the sheet mounts a second.
    await u.click(screen.getByRole('button', { name: /open navigation/i }));
    // Radix Dialog (the Sheet) marks the background app tree aria-hidden when
    // open, so the desktop sidebar's link drops from the default a11y query;
    // { hidden: true } counts both the desktop sidebar and the opened sheet.
    const sitesLinks = await screen.findAllByRole('link', { name: /sites/i, hidden: true });
    expect(sitesLinks.length).toBeGreaterThanOrEqual(2);
  });
});
