import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SiteThemeUpdateConfirmDialog } from '@/components/sites/SiteThemeUpdateConfirmDialog';
import type { Theme } from '@/types/api/themes';

const inactiveTheme: Theme = {
  slug: 'twentytwentyfour',
  name: 'Twenty Twenty-Four',
  version: '1.8',
  parent_slug: null,
  is_active: false,
  update_available: true,
  update_version: '1.9',
  update_state: 'idle',
  last_update_error: null,
  last_update_attempt_at: null,
  tested_up_to: null,
};

const activeTheme: Theme = {
  ...inactiveTheme,
  slug: 'twentytwentyfive',
  name: 'Twenty Twenty-Five',
  version: '1.2',
  update_version: '1.3',
  is_active: true,
};

describe('SiteThemeUpdateConfirmDialog', () => {
  it('inactive variant renders neutral copy + "Update theme" label', () => {
    render(
      <SiteThemeUpdateConfirmDialog
        theme={inactiveTheme}
        open
        onOpenChange={() => {}}
        onConfirm={() => {}}
      />,
    );
    expect(screen.getByText(/Update Twenty Twenty-Four/i)).toBeInTheDocument();
    expect(screen.getByText('1.8')).toBeInTheDocument();
    expect(screen.getByText('1.9')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Update theme$/ })).toBeInTheDocument();
    expect(screen.queryByLabelText(/warning|caution/i)).not.toBeInTheDocument();
  });

  it('active variant renders warning + amber button + "Yes, update active theme"', () => {
    render(
      <SiteThemeUpdateConfirmDialog
        theme={activeTheme}
        open
        onOpenChange={() => {}}
        onConfirm={() => {}}
      />,
    );
    expect(screen.getByText(/This is the active theme/i)).toBeInTheDocument();
    expect(screen.getByText(/Make sure you have a recent backup/i)).toBeInTheDocument();

    const confirmBtn = screen.getByRole('button', { name: /^Yes, update active theme$/ });
    expect(confirmBtn).toBeInTheDocument();
    expect(confirmBtn.className).toMatch(/bg-amber-600/);
    expect(confirmBtn.className).toMatch(/hover:bg-amber-700/);
  });

  it('Cancel has the default focus in both variants', () => {
    const { unmount } = render(
      <SiteThemeUpdateConfirmDialog
        theme={inactiveTheme}
        open
        onOpenChange={() => {}}
        onConfirm={() => {}}
      />,
    );
    expect(screen.getByRole('button', { name: /Cancel/ })).toHaveFocus();
    unmount();

    render(
      <SiteThemeUpdateConfirmDialog
        theme={activeTheme}
        open
        onOpenChange={() => {}}
        onConfirm={() => {}}
      />,
    );
    expect(screen.getByRole('button', { name: /Cancel/ })).toHaveFocus();
  });

  it('calls onConfirm when primary button clicked', async () => {
    const user = userEvent.setup();
    let confirmed = false;
    render(
      <SiteThemeUpdateConfirmDialog
        theme={activeTheme}
        open
        onOpenChange={() => {}}
        onConfirm={() => {
          confirmed = true;
        }}
      />,
    );
    await user.click(screen.getByRole('button', { name: /Yes, update active theme/ }));
    expect(confirmed).toBe(true);
  });

  it('calls onOpenChange(false) when Cancel clicked', async () => {
    const user = userEvent.setup();
    let opened = true;
    render(
      <SiteThemeUpdateConfirmDialog
        theme={inactiveTheme}
        open
        onOpenChange={(o) => {
          opened = o;
        }}
        onConfirm={() => {}}
      />,
    );
    await user.click(screen.getByRole('button', { name: /Cancel/ }));
    expect(opened).toBe(false);
  });
});
