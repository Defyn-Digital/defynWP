import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, beforeEach } from 'vitest';
import type { ReactNode } from 'react';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { SiteBrokenLinksPanel } from '@/components/sites/SiteBrokenLinksPanel';
import { setAccessToken } from '@/lib/apiClient';

function renderPanel(siteId = 7) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<SiteBrokenLinksPanel siteId={siteId} />, { wrapper });
}

function linksResponse(last_link_scan_at: string | null, links: unknown[] = []) {
  const severities = links.map((l) => (l as { severity: string }).severity);
  const counts = {
    broken: severities.filter((s) => s === 'broken').length,
    warning: severities.filter((s) => s === 'warning').length,
    total: links.length,
    internal: 0,
    external: links.length,
  };
  return HttpResponse.json({ data: { last_link_scan_at, counts, links }, error: null });
}

const BROKEN_LINK = {
  url: 'https://example.test/dead-page',
  status_code: 404,
  severity: 'broken',
  reason: 'not_found',
  link_type: 'external',
  source_url: 'https://mysite.test/about',
  source_title: 'About',
  anchor_text: 'Learn more',
  first_detected_at: '2026-06-15 03:00:00',
  last_detected_at: '2026-06-15 03:00:00',
};

describe('SiteBrokenLinksPanel', () => {
  beforeEach(() => {
    setAccessToken('fake');
  });

  it('renders "Not checked yet" when last_link_scan_at is null', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/sites/:id/broken-links', () => linksResponse(null)),
    );
    renderPanel();
    expect(await screen.findByText(/not checked yet/i)).toBeInTheDocument();
  });

  it('renders a broken row with status_code and url when issues payload is returned', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/sites/:id/broken-links', () =>
        linksResponse('2026-06-15 03:00:00', [BROKEN_LINK]),
      ),
    );
    renderPanel();
    // The url should appear in the row.
    expect(await screen.findByText(/example\.test\/dead-page/)).toBeInTheDocument();
    // Status code should be visible.
    expect(screen.getByText('404')).toBeInTheDocument();
    // Severity pill.
    expect(screen.getByText('broken')).toBeInTheDocument();
    // Source page URL group heading.
    expect(screen.getByText('https://mysite.test/about')).toBeInTheDocument();
  });

  it('clicking "Check links now" disables the button while scanning', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/sites/:id/broken-links', () => linksResponse(null)),
    );
    renderPanel();
    const btn = await screen.findByRole('button', { name: /check links now/i });
    await userEvent.click(btn);
    // After the POST fires, mutation enters pending/polling and the button is disabled.
    await waitFor(() => expect(btn).toBeDisabled());
  });
});
