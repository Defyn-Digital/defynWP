import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, beforeEach } from 'vitest';
import type { ReactNode } from 'react';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { SiteSecurityPanel } from '@/components/sites/SiteSecurityPanel';
import { setAccessToken } from '@/lib/apiClient';

function renderPanel(siteId = 7) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<SiteSecurityPanel siteId={siteId} />, { wrapper });
}

function findingsResponse(scanned_at: string | null, vulns: unknown[]) {
  return HttpResponse.json({ data: { scanned_at, vulnerabilities: vulns }, error: null });
}

describe('SiteSecurityPanel', () => {
  beforeEach(() => {
    setAccessToken('fake');
  });

  it('renders severity-grouped findings with fixed-in', async () => {
    server.use(http.get('*/wp-json/defyn/v1/sites/:id/vulnerabilities', () =>
      findingsResponse('2026-06-15 03:00:00', [
        { type: 'plugin', slug: 'wp-file-manager', component_name: 'WP File Manager',
          installed_version: '6.0', severity: 'critical', cvss_score: 9.8,
          cve: 'CVE-2024-1234', fixed_in: '6.9', title: 'RCE' },
      ])));
    renderPanel();
    expect(await screen.findByText('WP File Manager')).toBeInTheDocument();
    expect(screen.getByText(/6\.9/)).toBeInTheDocument();
    expect(screen.getByText(/critical/i)).toBeInTheDocument();
  });

  it('shows the clean state when scanned with zero findings', async () => {
    server.use(http.get('*/wp-json/defyn/v1/sites/:id/vulnerabilities', () =>
      findingsResponse('2026-06-15 03:00:00', [])));
    renderPanel();
    expect(await screen.findByText(/no known vulnerabilities/i)).toBeInTheDocument();
  });

  it('shows the not-scanned state when scanned_at is null', async () => {
    server.use(http.get('*/wp-json/defyn/v1/sites/:id/vulnerabilities', () =>
      findingsResponse(null, [])));
    renderPanel();
    expect(await screen.findByText(/not yet scanned/i)).toBeInTheDocument();
  });

  it('fires a scan when "Scan now" is clicked', async () => {
    renderPanel();
    const btn = await screen.findByRole('button', { name: /scan now/i });
    await userEvent.click(btn);
    // POST handler returns 202; mutation enters pending then polling state.
    await waitFor(() => expect(btn).toBeDisabled());
  });
});
