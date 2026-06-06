import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SitePluginUpdateConfirmDialog } from '@/components/sites/SitePluginUpdateConfirmDialog';
import type { Plugin } from '@/types/api/plugins';

const plugin: Plugin = {
  slug: 'akismet',
  name: 'Akismet',
  version: '5.7',
  update_available: true,
  update_version: '5.8',
  update_state: 'idle',
  last_update_error: null,
  last_update_attempt_at: null,
};

describe('SitePluginUpdateConfirmDialog', () => {
  it('renders the version diff when open', () => {
    render(
      <SitePluginUpdateConfirmDialog
        plugin={plugin}
        open
        onOpenChange={() => {}}
        onConfirm={() => {}}
      />,
    );
    expect(screen.getByText(/Update Akismet/i)).toBeInTheDocument();
    expect(screen.getByText('5.7')).toBeInTheDocument();
    expect(screen.getByText('5.8')).toBeInTheDocument();
    expect(screen.getByText(/maintenance mode/i)).toBeInTheDocument();
  });

  it('calls onConfirm when Update clicked', async () => {
    const user = userEvent.setup();
    let confirmed = false;
    render(
      <SitePluginUpdateConfirmDialog
        plugin={plugin}
        open
        onOpenChange={() => {}}
        onConfirm={() => {
          confirmed = true;
        }}
      />,
    );
    await user.click(screen.getByRole('button', { name: /^Update$/ }));
    expect(confirmed).toBe(true);
  });

  it('calls onOpenChange(false) when Cancel clicked', async () => {
    const user = userEvent.setup();
    let opened = true;
    render(
      <SitePluginUpdateConfirmDialog
        plugin={plugin}
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
