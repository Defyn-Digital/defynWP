import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import SiteDetail from '@/routes/SiteDetail';
import SitesList from '@/routes/SitesList';
import { resetMockSites, mockSites, resetMockActivity, seedMockActivity } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function renderAt(id: number) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[`/sites/${id}`]}>
        <Routes>
          <Route path="/sites" element={<SitesList />} />
          <Route path="/sites/:id" element={<SiteDetail />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('SiteDetail', () => {
  beforeEach(() => {
    resetMockSites();
    setAccessToken('fake');
  });

  it('shows pending state and a connecting message', async () => {
    mockSites.push({
      id: 1, url: 'https://x.test', label: 'X', status: 'pending',
      last_contact_at: null, last_sync_at: null, last_error: null,
      created_at: '2026-05-11 00:00:00',
      wp_version: null, php_version: null, active_theme: null,
      plugin_counts: null, theme_counts: null,
      ssl_status: null, ssl_expires_at: null,
    });
    renderAt(1);
    expect(await screen.findByText(/Connecting/i)).toBeInTheDocument();
    expect(await screen.findByText('https://x.test')).toBeInTheDocument();
  });

  it('shows active state with last_contact_at', async () => {
    mockSites.push({
      id: 1, url: 'https://x.test', label: '', status: 'active',
      last_contact_at: '2026-05-11 00:07:00', last_sync_at: '2026-05-11 00:07:00',
      last_error: null, created_at: '2026-05-11 00:00:00',
      wp_version: '6.9.4', php_version: '8.2.27',
      active_theme: { name: 'Twenty Twenty-Four', version: '1.0', parent: null },
      plugin_counts: { installed: 10, active: 5 },
      theme_counts: { installed: 2, active: 1 },
      ssl_status: 'enabled', ssl_expires_at: '2027-01-01T00:00:00Z',
    });
    renderAt(1);
    expect(await screen.findByText(/Connected/i)).toBeInTheDocument();
  });

  it('shows error state with last_error', async () => {
    mockSites.push({
      id: 1, url: 'https://x.test', label: '', status: 'error',
      last_contact_at: null, last_sync_at: null,
      last_error: 'Challenge signature invalid',
      created_at: '2026-05-11 00:00:00',
      wp_version: null, php_version: null, active_theme: null,
      plugin_counts: null, theme_counts: null,
      ssl_status: null, ssl_expires_at: null,
    });
    renderAt(1);
    expect(await screen.findByText('Challenge signature invalid')).toBeInTheDocument();
  });

  it('shows a not-found message on 404', async () => {
    renderAt(999);
    await waitFor(() => expect(screen.getByText(/not found/i)).toBeInTheDocument());
  });
});

describe('SiteDetail runtime info', () => {
  beforeEach(() => {
    resetMockSites();
    setAccessToken('fake');
  });

  it('shows runtime info when site is active and synced', async () => {
    mockSites.push({
      id: 1,
      url: 'https://example.test',
      label: 'Example',
      status: 'active',
      last_contact_at: '2026-05-31T00:00:01Z',
      last_sync_at: '2026-05-31T00:00:02Z',
      last_error: null,
      created_at: '2026-05-01 00:00:00',
      wp_version: '6.9.4',
      php_version: '8.2.27',
      active_theme: { name: 'Twenty Twenty-Four', version: '1.0', parent: null },
      plugin_counts: { installed: 10, active: 5 },
      theme_counts: { installed: 2, active: 1 },
      ssl_status: 'enabled',
      ssl_expires_at: '2027-01-01T00:00:00Z',
    });
    renderAt(1);

    expect(await screen.findByText(/6\.9\.4/)).toBeInTheDocument();
    expect(screen.getByText(/8\.2\.27/)).toBeInTheDocument();
    expect(screen.getByText(/Twenty Twenty-Four/i)).toBeInTheDocument();
    expect(screen.getByText(/10 installed, 5 active/i)).toBeInTheDocument();
    expect(screen.getByText(/2 installed, 1 active/i)).toBeInTheDocument();
    expect(screen.getByText(/enabled/i)).toBeInTheDocument();
  });

  it('shows "not yet synced" placeholder when wp_version is null', async () => {
    mockSites.push({
      id: 1,
      url: 'https://new.test',
      label: 'New',
      status: 'active',
      last_contact_at: null,
      last_sync_at: null,
      last_error: null,
      created_at: '2026-05-31 00:00:00',
      wp_version: null,
      php_version: null,
      active_theme: null,
      plugin_counts: null,
      theme_counts: null,
      ssl_status: null,
      ssl_expires_at: null,
    });

    renderAt(1);
    expect(await screen.findByText(/Not yet synced/i)).toBeInTheDocument();
  });

  it('omits optional runtime sections when fields are null but wp_version is present', async () => {
    mockSites.push({
      id: 1,
      url: 'https://partial.test',
      label: 'Partial',
      status: 'offline',
      last_contact_at: '2026-05-30T00:00:00Z',
      last_sync_at: '2026-05-30T00:00:00Z',
      last_error: null,
      created_at: '2026-05-01 00:00:00',
      wp_version: '6.8.0',
      php_version: '8.2.0',
      active_theme: null,
      plugin_counts: null,
      theme_counts: null,
      ssl_status: null,
      ssl_expires_at: null,
    });

    renderAt(1);

    expect(await screen.findByText(/6\.8\.0/)).toBeInTheDocument();
    expect(screen.queryByText(/Active theme/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/Plugins/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/SSL/i)).not.toBeInTheDocument();
  });
});

describe('SiteDetail actions', () => {
  beforeEach(() => {
    resetMockSites();
    setAccessToken('fake');
    mockSites.push({
      id: 1,
      url: 'https://example.test',
      label: 'Example',
      status: 'active',
      last_contact_at: '2026-05-31T00:00:01Z',
      last_sync_at: '2026-05-31T00:00:02Z',
      last_error: null,
      created_at: '2026-05-01 00:00:00',
      wp_version: '6.9.4',
      php_version: '8.2.27',
      active_theme: { name: 'Twenty Twenty-Four', version: '1.0', parent: null },
      plugin_counts: { installed: 10, active: 5 },
      theme_counts: { installed: 2, active: 1 },
      ssl_status: 'enabled',
      ssl_expires_at: '2027-01-01T00:00:00Z',
    });
  });

  it('shows Refresh, Ping, and Disconnect buttons on an active site', async () => {
    renderAt(1);
    expect(await screen.findByRole('button', { name: /Refresh/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Ping/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Disconnect/i })).toBeInTheDocument();
  });

  it('clicking Refresh transitions the button to syncing state', async () => {
    const user = userEvent.setup();
    renderAt(1);
    const button = await screen.findByRole('button', { name: /Refresh/i });
    await user.click(button);
    expect(await screen.findByRole('button', { name: /Syncing/i })).toBeInTheDocument();
  });

  it('Disconnect opens a confirmation dialog with Cancel option', async () => {
    const user = userEvent.setup();
    renderAt(1);
    await user.click(await screen.findByRole('button', { name: /Disconnect/i }));
    expect(await screen.findByText(/Disconnect Example/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Cancel/i })).toBeInTheDocument();
  });
});

describe('SiteDetail activity panel', () => {
  beforeEach(() => {
    resetMockSites();
    resetMockActivity();
    seedMockActivity();
    setAccessToken('fake');
    mockSites.push({
      id: 1,
      url: 'https://example.test',
      label: 'Example',
      status: 'active',
      last_contact_at: '2026-05-31T00:00:01Z',
      last_sync_at: '2026-05-31T00:00:02Z',
      last_error: null,
      created_at: '2026-05-01T00:00:00Z',
      wp_version: '6.9.4',
      php_version: '8.2.27',
      active_theme: { name: 'Twenty Twenty-Four', version: '1.0', parent: null },
      plugin_counts: { installed: 10, active: 5 },
      theme_counts: { installed: 2, active: 1 },
      ssl_status: 'enabled',
      ssl_expires_at: '2027-01-01T00:00:00Z',
    });
  });

  it('shows the Recent activity heading', async () => {
    renderAt(1);
    expect(await screen.findByText(/Recent activity/i)).toBeInTheDocument();
  });

  it('renders events for this site', async () => {
    renderAt(1);
    expect(await screen.findByText(/synced/i)).toBeInTheDocument();
    expect(await screen.findByText(/health_ok/i)).toBeInTheDocument();
  });

  it('shows empty state when no events for this site', async () => {
    resetMockActivity();
    renderAt(1);
    expect(await screen.findByText(/No activity yet/i)).toBeInTheDocument();
  });
});
