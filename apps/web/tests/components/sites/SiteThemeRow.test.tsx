import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { SiteThemeRow } from '@/components/sites/SiteThemeRow';
import type { Theme } from '@/types/api/themes';

const base: Theme = {
  slug: 'twentytwentyfive',
  name: 'Twenty Twenty-Five',
  version: '1.2',
  parent_slug: null,
  is_active: false,
  update_available: false,
  update_version: null,
  update_state: 'idle',
  last_update_error: null,
  last_update_attempt_at: null,
  tested_up_to: null,
};

function wrap(t: Theme) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <table>
        <tbody>
          <SiteThemeRow theme={t} siteId={1} />
        </tbody>
      </table>
    </QueryClientProvider>,
  );
}

describe('SiteThemeRow', () => {
  it('idle non-upgradable renders a dash and no Update button', () => {
    wrap(base);
    expect(screen.queryByRole('button', { name: /Update/ })).not.toBeInTheDocument();
    expect(screen.getByText('—')).toBeInTheDocument();
  });

  it('idle upgradable renders the Update button + version diff', () => {
    wrap({ ...base, update_available: true, update_version: '1.3' });
    expect(screen.getByText('→ 1.3')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Update$/ })).toBeInTheDocument();
  });

  it('queued and updating render the same disabled Updating… button', () => {
    const queued = wrap({
      ...base,
      update_available: true,
      update_version: '1.3',
      update_state: 'queued',
    });
    expect(screen.getByRole('button', { name: /Updating/i })).toBeDisabled();
    queued.unmount();

    wrap({
      ...base,
      update_available: true,
      update_version: '1.3',
      update_state: 'updating',
    });
    expect(screen.getByRole('button', { name: /Updating/i })).toBeDisabled();
  });

  it('failed state shows warning icon and Retry button', async () => {
    const user = userEvent.setup();
    wrap({
      ...base,
      update_available: true,
      update_version: '1.3',
      update_state: 'failed',
      last_update_error: 'Could not copy file. /wp-content/themes/twentytwentyfive/style.css',
    });
    expect(screen.getByRole('button', { name: /^Retry$/ })).toBeInTheDocument();

    const warningIcon = screen.getByLabelText(/update failed/i);
    expect(warningIcon).toBeInTheDocument();

    await user.hover(warningIcon);
    const matches = await screen.findAllByText(/Could not copy file/i);
    expect(matches.length).toBeGreaterThan(0);
  });

  it('renders the Active badge on the active theme', () => {
    wrap({ ...base, is_active: true });
    expect(screen.getByText('Active')).toBeInTheDocument();
  });

  it('renders the Parent: <slug> badge on a child theme', () => {
    wrap({ ...base, slug: 'astra-child', name: 'Astra Child', parent_slug: 'astra' });
    expect(screen.getByText(/Parent:\s*astra/i)).toBeInTheDocument();
  });

  it('renders both Active and Parent badges on an active child theme', () => {
    wrap({
      ...base,
      slug: 'astra-child',
      name: 'Astra Child',
      parent_slug: 'astra',
      is_active: true,
    });
    expect(screen.getByText('Active')).toBeInTheDocument();
    expect(screen.getByText(/Parent:\s*astra/i)).toBeInTheDocument();
  });
});
