import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import SiteDetail from '@/routes/SiteDetail';
import { mockSiteThemes, resetMockSiteThemes, mockSites, resetMockSites } from '@/test/handlers';
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

describe('SiteDetail — themes integration', () => {
  beforeEach(() => {
    resetMockSites();
    resetMockSiteThemes();
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
    });
  });

  it('renders the active theme name from useSiteThemes in the header chip', async () => {
    mockSiteThemes[1] = [
      {
        slug: 'twentytwentyfive',
        name: 'Twenty Twenty-Five',
        version: '1.2',
        parent_slug: null,
        is_active: true,
        update_available: false,
        update_version: null,
        update_state: 'idle',
        last_update_error: null,
        last_update_attempt_at: null,
      },
      {
        slug: 'astra',
        name: 'Astra',
        version: '4.5',
        parent_slug: null,
        is_active: false,
        update_available: false,
        update_version: null,
        update_state: 'idle',
        last_update_error: null,
        last_update_attempt_at: null,
      },
    ];

    renderRoute(1);
    await waitFor(() => {
      const chip = screen.getByLabelText(/active theme/i);
      expect(chip).toHaveTextContent('Active theme:');
      expect(chip).toHaveTextContent('Twenty Twenty-Five');
    });
  });

  it('renders "—" in the header chip when themes list is empty', async () => {
    mockSiteThemes[1] = [];
    renderRoute(1);
    await waitFor(() => {
      const chip = screen.getByLabelText(/active theme/i);
      expect(chip).toHaveTextContent('—');
    });
  });

  it('stacks SiteThemesPanel below SitePluginsPanel', async () => {
    mockSiteThemes[1] = [];
    renderRoute(1);
    await waitFor(() => expect(screen.getByRole('heading', { name: /Themes/i })).toBeInTheDocument());

    const pluginsHeader = screen.getByRole('heading', { name: /Plugins/i });
    const themesHeader = screen.getByRole('heading', { name: /Themes/i });
    expect(pluginsHeader.compareDocumentPosition(themesHeader)).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
  });
});
