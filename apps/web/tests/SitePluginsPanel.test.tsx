import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it } from 'vitest';
import type { ReactElement } from 'react';
import { SitePluginsPanel } from '@/components/sites/SitePluginsPanel';
import { mockSitePlugins, resetMockSitePlugins } from '@/test/handlers';

function renderWithClient(ui: ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('SitePluginsPanel', () => {
  beforeEach(() => resetMockSitePlugins());

  it('renders plugin rows from the API', async () => {
    mockSitePlugins[42] = {
      plugins: [
        { slug: 'akismet/akismet.php', name: 'Akismet', version: '5.3.1', update_available: true, update_version: '5.3.5', update_state: 'idle', last_update_error: null, last_update_attempt_at: null, tested_up_to: null },
        { slug: 'rank-math/rank-math.php', name: 'Rank Math', version: '1.0.234', update_available: false, update_version: null, update_state: 'idle', last_update_error: null, last_update_attempt_at: null, tested_up_to: null },
      ],
      last_synced_at: '2026-06-04 11:30:00',
    };

    renderWithClient(<SitePluginsPanel siteId={42} />);

    await waitFor(() => expect(screen.getByText('Akismet')).toBeInTheDocument());
    expect(screen.getByText('Rank Math')).toBeInTheDocument();
    expect(screen.getByText(/5\.3\.5/)).toBeInTheDocument();
  });

  it('filters to updates-only when toggle is on', async () => {
    mockSitePlugins[42] = {
      plugins: [
        { slug: 'a.php', name: 'A', version: '1.0', update_available: false, update_version: null, update_state: 'idle', last_update_error: null, last_update_attempt_at: null, tested_up_to: null },
        { slug: 'b.php', name: 'B', version: '2.0', update_available: true, update_version: '2.1', update_state: 'idle', last_update_error: null, last_update_attempt_at: null, tested_up_to: null },
      ],
      last_synced_at: '2026-06-04 11:30:00',
    };

    renderWithClient(<SitePluginsPanel siteId={42} />);

    await waitFor(() => expect(screen.getByText('A')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('switch', { name: /updates only/i }));

    expect(screen.queryByText('A')).not.toBeInTheDocument();
    expect(screen.getByText('B')).toBeInTheDocument();
  });

  it('renders the empty state when site has zero plugins but was synced', async () => {
    mockSitePlugins[42] = { plugins: [], last_synced_at: '2026-06-04 11:30:00' };

    renderWithClient(<SitePluginsPanel siteId={42} />);

    await waitFor(() =>
      expect(screen.getByText(/no plugins installed/i)).toBeInTheDocument(),
    );
  });

  it('renders the "not yet synced" banner when last_synced_at is null', async () => {
    mockSitePlugins[42] = { plugins: [], last_synced_at: null };

    renderWithClient(<SitePluginsPanel siteId={42} />);

    await waitFor(() =>
      expect(screen.getByText(/plugin inventory not yet captured/i)).toBeInTheDocument(),
    );
  });
});
