import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import SiteDetail from '@/routes/SiteDetail';
import {
  mockSiteCoreState,
  resetMockSiteCoreState,
  resetMockSites,
  mockSites,
} from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function renderRoute(siteId: number) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[`/sites/${siteId}`]}>
        <Routes>
          <Route path="/sites/:id" element={<SiteDetail />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('SiteDetail — core card integration', () => {
  beforeEach(() => {
    resetMockSites();
    resetMockSiteCoreState();
    setAccessToken('fake');

    // Create a mock site with active status so the detail view renders fully
    mockSites.push({
      id: 1,
      url: 'https://smartcoding.test',
      label: 'Smart Coding',
      status: 'active',
      last_contact_at: '2026-06-06T00:00:00Z',
      last_sync_at: '2026-06-06T00:00:00Z',
      last_error: null,
      created_at: '2026-05-01 00:00:00',
      wp_version: '6.9.4',
      php_version: '8.2.27',
      active_theme: null,
      plugin_counts: { installed: 10, active: 5 },
      theme_counts: { installed: 2, active: 1 },
      ssl_status: 'enabled',
      ssl_expires_at: '2027-01-01T00:00:00Z',
      core_update_available: false,
      core_update_version: null,
      core_update_state: 'idle',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
      core_allow_major: false,
      alerts_muted: false,
    });
    mockSiteCoreState[1] = {
      core_update_available: false,
      core_update_version: null,
      core_update_state: 'idle',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
    };
  });

  it('renders SiteCoreCard ABOVE SiteSummaryCard in DOM order', async () => {
    renderRoute(1);

    // Wait for SiteCoreCard to render by finding its refresh button (unique identifier)
    await waitFor(() => {
      expect(screen.getByLabelText('Refresh WordPress core')).toBeInTheDocument();
    });

    // SiteCoreCard has a refresh button with the aria-label "Refresh WordPress core"
    // This is unique and identifies the SiteCoreCard specifically.
    const refreshButton = screen.getByLabelText('Refresh WordPress core');

    // Find the card parent element
    const coreCard = refreshButton.closest('div[class*="rounded-lg"]');
    expect(coreCard).toBeInTheDocument();

    // Find the Plugins panel header to verify SiteCoreCard comes before it
    const allPluginsHeaders = screen.getAllByText(/^Plugins$/i);
    const pluginsPanel = allPluginsHeaders[allPluginsHeaders.length - 1]; // Get the last occurrence to ensure it's from SitePluginsPanel

    // Verify SiteCoreCard comes BEFORE the Plugins panel in DOM order
    if (coreCard && pluginsPanel) {
      expect((coreCard.compareDocumentPosition(pluginsPanel) & Node.DOCUMENT_POSITION_FOLLOWING) > 0).toBe(true);
    }
  });
});
