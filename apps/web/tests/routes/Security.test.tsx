import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { Security } from '@/routes/Security';

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/security']}>
        <Routes><Route path="/security" element={<Security />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const fleet = (overrides: object) => ({
  summary: { total_sites: 0, scanned_sites: 0, sites_at_risk: 0, critical: 0, high: 0, medium: 0, low: 0 },
  sites: [],
  generated_at: '2026-06-15 03:00:00',
  ...overrides,
});

describe('Security page', () => {
  it('shows the empty state when no sites', async () => {
    server.use(http.get('*/wp-json/defyn/v1/security', () => HttpResponse.json(fleet({}))));
    renderPage();
    await waitFor(() => expect(screen.getByText(/no sites yet/i)).toBeInTheDocument());
  });

  it('shows an all-clear banner when sites exist but none at risk', async () => {
    server.use(http.get('*/wp-json/defyn/v1/security', () => HttpResponse.json(fleet({
      summary: { total_sites: 1, scanned_sites: 1, sites_at_risk: 0, critical: 0, high: 0, medium: 0, low: 0 },
      sites: [{ site_id: 5, label: 'CleanCo', url: 'https://c.test', last_security_scan_at: '2026-06-15 02:00:00',
        counts: { critical: 0, high: 0, medium: 0, low: 0, total: 0 } }],
    }))));
    renderPage();
    await waitFor(() => expect(screen.getByText(/no known vulnerabilities across the fleet/i)).toBeInTheDocument());
    expect(screen.getByText('CleanCo')).toBeInTheDocument(); // table still lists the site
  });

  it('renders the strip + table when a site is at risk', async () => {
    server.use(http.get('*/wp-json/defyn/v1/security', () => HttpResponse.json(fleet({
      summary: { total_sites: 1, scanned_sites: 1, sites_at_risk: 1, critical: 1, high: 0, medium: 0, low: 0 },
      sites: [{ site_id: 6, label: 'RiskyCo', url: 'https://r.test', last_security_scan_at: '2026-06-15 02:00:00',
        counts: { critical: 1, high: 0, medium: 0, low: 0, total: 1 } }],
    }))));
    renderPage();
    await waitFor(() => expect(screen.getByText('RiskyCo')).toBeInTheDocument());
  });
});
