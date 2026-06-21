import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import Reports from '@/routes/Reports';

const site = {
  id: 7,
  url: 'https://acme.test',
  label: 'Acme Co',
  status: 'active',
  last_contact_at: null,
  last_sync_at: null,
  last_error: null,
  created_at: '2026-06-21 00:00:00',
  wp_version: '6.5',
  php_version: '8.2',
  active_theme: null,
  plugin_counts: null,
  theme_counts: null,
  ssl_status: null,
  ssl_expires_at: null,
  core_update_available: false,
  core_update_version: null,
  core_update_state: 'idle',
  last_core_update_error: null,
  last_core_update_attempt_at: null,
  core_allow_major: false,
  alerts_muted: false,
  auto_send_reports: false,
};

function renderReports() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/reports']}>
        <Reports />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Reports index', () => {
  it('lists each site with a link to its report', async () => {
    server.use(http.get('*/wp-json/defyn/v1/sites', () => HttpResponse.json({ sites: [site] })));
    renderReports();

    expect(screen.getByRole('heading', { name: /reports/i })).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText('Acme Co')).toBeInTheDocument());
    expect(screen.getByRole('link', { name: /view report/i })).toHaveAttribute('href', '/sites/7/report');
  });

  it('shows an empty state when there are no sites', async () => {
    server.use(http.get('*/wp-json/defyn/v1/sites', () => HttpResponse.json({ sites: [] })));
    renderReports();

    await waitFor(() => expect(screen.getByText(/no sites yet/i)).toBeInTheDocument());
    expect(screen.queryByRole('link', { name: /view report/i })).not.toBeInTheDocument();
  });
});
