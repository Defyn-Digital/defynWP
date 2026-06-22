import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { SiteCoreCard } from '@/components/sites/SiteCoreCard';
import {
  mockSiteCoreState,
  resetMockSiteCoreState,
  resetMockSites,
  seedMockSitesAllStatuses,
} from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function wrap(siteId: number) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <SiteCoreCard siteId={siteId} />
    </QueryClientProvider>,
  );
}

describe('SiteCoreCard', () => {
  beforeEach(() => {
    resetMockSites();
    resetMockSiteCoreState();
    seedMockSitesAllStatuses();
    setAccessToken('fake');
  });

  it('idle no-update renders only the version + meta line', async () => {
    mockSiteCoreState[1] = {
      core_update_available: false,
      core_update_version: null,
      core_update_state: 'idle',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
      is_auto_update_enabled: true,
    };
    wrap(1);

    await waitFor(() => expect(screen.getByText(/WordPress/i)).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /^Update/ })).not.toBeInTheDocument();
    expect(screen.getByText(/Auto-updates ON/i)).toBeInTheDocument();
  });

  it('idle update-available renders version diff + Update button', async () => {
    // Use a genuine minor bump from the seeded site's wp_version (6.9.4).
    // 7.0.1 would be a MAJOR bump (different major segment), routing the component
    // to the isBlockedMajor branch which hides the Update button. 6.9.5 shares the
    // same major+minor (6.9) so isMinorBump() returns true → Update button shown.
    mockSiteCoreState[1] = {
      core_update_available: true,
      core_update_version: '6.9.5',
      core_update_state: 'idle',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
      is_auto_update_enabled: false,
    };
    wrap(1);

    await waitFor(() => expect(screen.getByRole('button', { name: /Update to 6\.9\.5/i })).toBeInTheDocument());
    expect(screen.queryByText(/Update available/i)).toBeInTheDocument();
  });

  it('updating state renders full-width amber + spinner + duration copy', async () => {
    mockSiteCoreState[1] = {
      core_update_available: true,
      core_update_version: '7.0.1',
      core_update_state: 'updating',
      last_core_update_error: null,
      last_core_update_attempt_at: '2026-06-07 09:00:00',
    };
    wrap(1);

    await waitFor(() => expect(screen.getByText(/Upgrading/i)).toBeInTheDocument());
    expect(screen.getByText(/30.+90 seconds/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Update to/ })).not.toBeInTheDocument();
  });

  it('failed state renders red banner + Retry button + tooltip on hover', async () => {
    const user = userEvent.setup();
    // Use a genuine minor bump (6.9.5 from seeded wp_version 6.9.4) so the component
    // reaches the minor/failed code path where "Retry update" button is rendered.
    // 7.0.1 is a MAJOR bump → isBlockedMajor branch → no Retry button.
    mockSiteCoreState[1] = {
      core_update_available: true,
      core_update_version: '6.9.5',
      core_update_state: 'failed',
      last_core_update_error: 'Disk full at /tmp during package extract',
      last_core_update_attempt_at: '2026-06-07 09:00:00',
    };
    wrap(1);

    await waitFor(() => expect(screen.getByText(/Last update attempt failed/i)).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /Retry update/i })).toBeInTheDocument();

    const warningIcon = screen.getByLabelText(/update failed/i);
    await user.hover(warningIcon);
    expect(await screen.findByText(/Disk full at \/tmp/i)).toBeInTheDocument();
  });
});
