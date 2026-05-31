import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import Activity from '@/routes/Activity';
import { resetMockActivity, seedMockActivity } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function renderActivity() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/activity']}>
        <Activity />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Activity route', () => {
  beforeEach(() => {
    resetMockActivity();
    setAccessToken('fake');
  });

  it('renders events newest-first', async () => {
    seedMockActivity();
    renderActivity();
    // Seeded events: id=1 site.synced (newest), id=2 site.health_ok, id=3 site.connected (oldest).
    const rows = await screen.findAllByTestId('activity-row');
    expect(rows).toHaveLength(3);
    expect(rows[0]).toHaveTextContent(/site\.synced/i);
    expect(rows[1]).toHaveTextContent(/site\.health_ok/i);
    expect(rows[2]).toHaveTextContent(/site\.connected/i);
  });

  it('shows empty state when no events match', async () => {
    renderActivity();
    expect(await screen.findByText(/No events match your filters\./i)).toBeInTheDocument();
  });

  it('filters by Health chip', async () => {
    seedMockActivity();
    const user = userEvent.setup();
    renderActivity();
    await screen.findAllByTestId('activity-row');

    await user.click(screen.getByRole('button', { name: /^Health$/i }));

    // After clicking Health, only site.health_ok should remain (site.synced filtered out).
    expect(screen.queryByText(/site\.synced/)).not.toBeInTheDocument();
    expect(screen.getByText(/site\.health_ok/)).toBeInTheDocument();
  });
});
