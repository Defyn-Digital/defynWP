import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import SitesList from '@/routes/SitesList';
import { resetMockSites, seedMockSitesAllStatuses } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function renderSitesList() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/sites']}>
        <SitesList />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('SitesList — empty state (no sites)', () => {
  beforeEach(() => {
    resetMockSites();
    setAccessToken('fake');
  });

  it('shows the no-sites-yet empty state when the user has no sites', async () => {
    renderSitesList();
    expect(await screen.findByText(/No sites yet/i)).toBeInTheDocument();
  });
});

describe('SitesList — filters and search', () => {
  beforeEach(() => {
    resetMockSites();
    seedMockSitesAllStatuses();
    setAccessToken('fake');
  });

  it('renders status chips with per-status counts computed from the full list', async () => {
    renderSitesList();
    expect(await screen.findByRole('button', { name: /^All \(4\)$/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Active \(1\)$/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Offline \(1\)$/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Error \(1\)$/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Pending \(1\)$/i })).toBeInTheDocument();
  });

  it('renders all seeded sites by default ("All" chip active)', async () => {
    renderSitesList();
    expect(await screen.findByText('Example')).toBeInTheDocument();
    expect(screen.getByText('Offline Site')).toBeInTheDocument();
    expect(screen.getByText('Broken Site')).toBeInTheDocument();
    expect(screen.getByText('Pending Site')).toBeInTheDocument();
  });

  it('filters the list to only offline sites when the Offline chip is clicked', async () => {
    const user = userEvent.setup();
    renderSitesList();
    await screen.findByText('Example');

    await user.click(screen.getByRole('button', { name: /^Offline \(1\)$/i }));

    expect(screen.getByText('Offline Site')).toBeInTheDocument();
    expect(screen.queryByText('Example')).not.toBeInTheDocument();
    expect(screen.queryByText('Broken Site')).not.toBeInTheDocument();
    expect(screen.queryByText('Pending Site')).not.toBeInTheDocument();
  });

  it('filters by URL or label substring (case-insensitive)', async () => {
    const user = userEvent.setup();
    renderSitesList();
    await screen.findByText('Example');

    const search = screen.getByPlaceholderText(/search/i);
    await user.type(search, 'offline');

    expect(screen.getByText('Offline Site')).toBeInTheDocument();
    expect(screen.queryByText('Example')).not.toBeInTheDocument();
    expect(screen.queryByText('Broken Site')).not.toBeInTheDocument();
    expect(screen.queryByText('Pending Site')).not.toBeInTheDocument();
  });

  it('matches label as well as URL (case-insensitive)', async () => {
    const user = userEvent.setup();
    renderSitesList();
    await screen.findByText('Example');

    const search = screen.getByPlaceholderText(/search/i);
    await user.type(search, 'BROKEN');

    expect(screen.getByText('Broken Site')).toBeInTheDocument();
    expect(screen.queryByText('Example')).not.toBeInTheDocument();
  });

  it('combines status filter AND search (intersection)', async () => {
    const user = userEvent.setup();
    renderSitesList();
    await screen.findByText('Example');

    await user.click(screen.getByRole('button', { name: /^Active \(1\)$/i }));
    expect(screen.getByText('Example')).toBeInTheDocument();
    expect(screen.queryByText('Offline Site')).not.toBeInTheDocument();

    const search = screen.getByPlaceholderText(/search/i);
    await user.type(search, 'offline');

    await waitFor(() => {
      expect(screen.queryByText('Example')).not.toBeInTheDocument();
    });
    expect(screen.queryByText('Offline Site')).not.toBeInTheDocument();
    expect(screen.getByText(/no sites match/i)).toBeInTheDocument();
  });

  it('preserves chip counts when filtering (counts are based on the full list)', async () => {
    const user = userEvent.setup();
    renderSitesList();
    await screen.findByText('Example');

    await user.click(screen.getByRole('button', { name: /^Active \(1\)$/i }));

    expect(screen.getByRole('button', { name: /^All \(4\)$/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Offline \(1\)$/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Error \(1\)$/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Pending \(1\)$/i })).toBeInTheDocument();
  });
});
