import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { SitePluginsRow } from '@/components/sites/SitePluginsRow';
import type { Plugin } from '@/types/api/plugins';

const base: Plugin = {
  slug: 'akismet',
  name: 'Akismet',
  version: '5.7',
  update_available: false,
  update_version: null,
  update_state: 'idle',
  last_update_error: null,
  last_update_attempt_at: null,
  tested_up_to: null,
};

function wrap(p: Plugin) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <table>
        <tbody>
          <SitePluginsRow plugin={p} siteId={1} />
        </tbody>
      </table>
    </QueryClientProvider>,
  );
}

describe('SitePluginsRow', () => {
  it('idle non-upgradable renders a dash and no button', () => {
    wrap(base);
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.getByText('—')).toBeInTheDocument();
  });

  it('idle upgradable renders the badge + Update button', () => {
    wrap({ ...base, update_available: true, update_version: '5.8' });
    expect(screen.getByText(/→\s*5\.8/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Update$/ })).toBeInTheDocument();
  });

  it('queued and updating render the same disabled Updating… button', () => {
    const queued = wrap({
      ...base,
      update_available: true,
      update_version: '5.8',
      update_state: 'queued',
    });
    expect(screen.getByRole('button', { name: /Updating/i })).toBeDisabled();
    queued.unmount();

    wrap({
      ...base,
      update_available: true,
      update_version: '5.8',
      update_state: 'updating',
    });
    expect(screen.getByRole('button', { name: /Updating/i })).toBeDisabled();
  });

  it('failed state shows warning icon and Retry button', async () => {
    const user = userEvent.setup();
    wrap({
      ...base,
      update_available: true,
      update_version: '5.8',
      update_state: 'failed',
      last_update_error: 'Could not copy file. /wp-content/upgrade/akismet/akismet.php',
    });
    expect(screen.getByRole('button', { name: /^Retry$/ })).toBeInTheDocument();

    const warningIcon = screen.getByLabelText(/update failed/i);
    expect(warningIcon).toBeInTheDocument();

    await user.hover(warningIcon);
    // Radix renders tooltip content + a visually-hidden a11y span, so there are
    // two matches once the tooltip opens. findAllByText resolves once at least one exists.
    const matches = await screen.findAllByText(/Could not copy file/i);
    expect(matches.length).toBeGreaterThan(0);
  });
});
