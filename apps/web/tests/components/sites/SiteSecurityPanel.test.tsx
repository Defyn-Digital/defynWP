import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, beforeEach, vi } from 'vitest';
import type { ReactNode } from 'react';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { SiteSecurityPanel } from '@/components/sites/SiteSecurityPanel';
import { setAccessToken } from '@/lib/apiClient';

// Spy on the dismiss mutation so the per-row Dismiss / Restore controls can be
// asserted without driving a real network round-trip.
const dismissSpy = vi.fn();
vi.mock('@/lib/mutations/useDismissVulnerability', () => ({
  useDismissVulnerability: () => ({
    dismiss: dismissSpy,
    isPending: false,
    error: null,
  }),
}));

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
    dismissSpy.mockClear();
  });

  it('renders severity-grouped findings with fixed-in', async () => {
    server.use(http.get('*/wp-json/defyn/v1/sites/:id/vulnerabilities', () =>
      findingsResponse('2026-06-15 03:00:00', [
        { type: 'plugin', slug: 'wp-file-manager', component_name: 'WP File Manager',
          installed_version: '6.0', severity: 'critical', cvss_score: 9.8,
          cve: 'CVE-2024-1234', fixed_in: '6.9', title: 'RCE',
          source_id: 'src-wfm', dismissed: false },
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

  // --- P4.3b: active vs dismissed split ---

  const ACTIVE_FINDING = {
    type: 'plugin', slug: 'wp-file-manager', component_name: 'WP File Manager',
    installed_version: '6.0', severity: 'critical', cvss_score: 9.8,
    cve: 'CVE-2024-1234', fixed_in: '6.9', title: 'RCE',
    source_id: 'src-active', dismissed: false,
  };
  const DISMISSED_FINDING = {
    type: 'theme', slug: 'twentytwenty', component_name: 'Twenty Twenty',
    installed_version: '1.2', severity: 'high', cvss_score: 7.1,
    cve: 'CVE-2023-9999', fixed_in: '1.9', title: 'XSS',
    source_id: 'src-dismissed', dismissed: true,
  };

  function serveActiveAndDismissed() {
    server.use(http.get('*/wp-json/defyn/v1/sites/:id/vulnerabilities', () =>
      findingsResponse('2026-06-15 03:00:00', [ACTIVE_FINDING, DISMISSED_FINDING])));
  }

  it('renders the active finding in a severity group', async () => {
    serveActiveAndDismissed();
    renderPanel();
    expect(await screen.findByText('WP File Manager')).toBeInTheDocument();
    expect(screen.getByText(/critical/i)).toBeInTheDocument();
  });

  it('renders a "Dismissed (1)" heading and the dismissed finding with a Restore control', async () => {
    serveActiveAndDismissed();
    renderPanel();
    expect(await screen.findByText(/Dismissed \(1\)/)).toBeInTheDocument();
    expect(screen.getByText('Twenty Twenty')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Restore Twenty Twenty/i })).toBeInTheDocument();
  });

  it('dismisses the active finding with its fingerprint when Dismiss is clicked', async () => {
    serveActiveAndDismissed();
    renderPanel();
    const dismissBtn = await screen.findByRole('button', { name: /Dismiss WP File Manager/i });
    await userEvent.click(dismissBtn);
    expect(dismissSpy).toHaveBeenCalledWith({
      type: 'plugin',
      slug: 'wp-file-manager',
      source_id: 'src-active',
      dismissed: true,
    });
  });

  it('restores the dismissed finding when Restore is clicked', async () => {
    serveActiveAndDismissed();
    renderPanel();
    const restoreBtn = await screen.findByRole('button', { name: /Restore Twenty Twenty/i });
    await userEvent.click(restoreBtn);
    expect(dismissSpy).toHaveBeenCalledWith({
      type: 'theme',
      slug: 'twentytwenty',
      source_id: 'src-dismissed',
      dismissed: false,
    });
  });

  it('does not render a Dismissed heading when every finding is active', async () => {
    server.use(http.get('*/wp-json/defyn/v1/sites/:id/vulnerabilities', () =>
      findingsResponse('2026-06-15 03:00:00', [ACTIVE_FINDING])));
    renderPanel();
    expect(await screen.findByText('WP File Manager')).toBeInTheDocument();
    expect(screen.queryByText(/Dismissed \(/)).not.toBeInTheDocument();
  });

  it('counts only active findings in the meta line', async () => {
    serveActiveAndDismissed();
    renderPanel();
    // Wait for data to land, then assert the meta line says "1 vulnerability"
    // (active only) even though 2 findings exist in total.
    await screen.findByText('WP File Manager');
    const header = screen.getByRole('heading', { name: 'Security' }).closest('header');
    expect(header).not.toBeNull();
    expect(within(header as HTMLElement).getByText(/^1 vulnerability\b/)).toBeInTheDocument();
    expect(within(header as HTMLElement).queryByText(/2 vulnerabilities/)).not.toBeInTheDocument();
  });

  it('still shows the Dismissed section when the active set is clean', async () => {
    server.use(http.get('*/wp-json/defyn/v1/sites/:id/vulnerabilities', () =>
      findingsResponse('2026-06-15 03:00:00', [DISMISSED_FINDING])));
    renderPanel();
    expect(await screen.findByText(/no known vulnerabilities/i)).toBeInTheDocument();
    expect(screen.getByText(/Dismissed \(1\)/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Restore Twenty Twenty/i })).toBeInTheDocument();
  });
});
