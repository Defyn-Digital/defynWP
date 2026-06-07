import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ConfirmUpdateCoreDialog } from '@/components/sites/ConfirmUpdateCoreDialog';
import type { Site } from '@/types/api';

const baseSite = {
  id: 1,
  url: 'https://smartcoding.test',
  label: 'Smart',
  status: 'active',
  last_contact_at: null,
  last_sync_at: null,
  last_error: null,
  created_at: '2026-06-07 00:00:00',
  wp_version: '7.0',
  php_version: '8.3.31',
  active_theme: null,
  plugin_counts: null,
  theme_counts: null,
  ssl_status: null,
  ssl_expires_at: null,
  core_update_available: true,
  core_update_version: '7.0.1',
  core_update_state: 'idle' as const,
  last_core_update_error: null,
  last_core_update_attempt_at: null,
  is_minor_update: true,
  is_auto_update_enabled: false,
} satisfies Site;

describe('ConfirmUpdateCoreDialog', () => {
  it('renders title with version diff', () => {
    render(
      <ConfirmUpdateCoreDialog
        site={baseSite}
        open
        onOpenChange={() => {}}
        onConfirm={() => {}}
      />,
    );
    expect(screen.getByText(/Update WordPress 7\.0\s*->\s*7\.0\.1/i)).toBeInTheDocument();
  });

  it('renders BOTH warning banners (downtime + downgrade)', () => {
    render(
      <ConfirmUpdateCoreDialog
        site={baseSite}
        open
        onOpenChange={() => {}}
        onConfirm={() => {}}
      />,
    );
    expect(screen.getByText(/Site goes briefly offline/i)).toBeInTheDocument();
    expect(screen.getByText(/Downgrades require SFTP/i)).toBeInTheDocument();
  });

  it('renders Auto-updates ON paragraph when is_auto_update_enabled === true', () => {
    render(
      <ConfirmUpdateCoreDialog
        site={{ ...baseSite, is_auto_update_enabled: true }}
        open
        onOpenChange={() => {}}
        onConfirm={() => {}}
      />,
    );
    expect(screen.getByText(/install this update automatically/i)).toBeInTheDocument();
  });

  it('OMITS Auto-updates ON paragraph when is_auto_update_enabled !== true', () => {
    render(
      <ConfirmUpdateCoreDialog
        site={{ ...baseSite, is_auto_update_enabled: false }}
        open
        onOpenChange={() => {}}
        onConfirm={() => {}}
      />,
    );
    expect(screen.queryByText(/install this update automatically/i)).not.toBeInTheDocument();
  });

  it('renders amber primary button with the exact label', () => {
    render(
      <ConfirmUpdateCoreDialog
        site={baseSite}
        open
        onOpenChange={() => {}}
        onConfirm={() => {}}
      />,
    );
    const btn = screen.getByRole('button', { name: /^Yes, update WordPress core$/ });
    expect(btn).toBeInTheDocument();
    expect(btn.className).toMatch(/bg-amber-600/);
    expect(btn.className).toMatch(/hover:bg-amber-700/);
  });

  it('Cancel has the default focus', () => {
    render(
      <ConfirmUpdateCoreDialog
        site={baseSite}
        open
        onOpenChange={() => {}}
        onConfirm={() => {}}
      />,
    );
    expect(screen.getByRole('button', { name: /^Cancel$/ })).toHaveFocus();
  });

  it('calls onConfirm when amber button clicked', async () => {
    const user = userEvent.setup();
    let confirmed = false;
    render(
      <ConfirmUpdateCoreDialog
        site={baseSite}
        open
        onOpenChange={() => {}}
        onConfirm={() => {
          confirmed = true;
        }}
      />,
    );
    await user.click(screen.getByRole('button', { name: /Yes, update WordPress core/ }));
    expect(confirmed).toBe(true);
  });

  it('calls onOpenChange(false) when Cancel clicked', async () => {
    const user = userEvent.setup();
    let opened = true;
    render(
      <ConfirmUpdateCoreDialog
        site={baseSite}
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
