import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, beforeEach } from 'vitest';
import type { ReactNode } from 'react';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { SitePerformancePanel } from '@/components/sites/SitePerformancePanel';
import { setAccessToken } from '@/lib/apiClient';

function renderPanel(siteId = 7) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<SitePerformancePanel siteId={siteId} />, { wrapper });
}

function latestResponse(latest: unknown) {
  return HttpResponse.json({ data: { latest }, error: null });
}

const SNAPSHOT = {
  id: 11,
  site_id: 7,
  mobile_score: 82,
  mobile_lcp_ms: 2100,
  mobile_cls: 0.14,
  mobile_inp_ms: 180,
  desktop_score: 96,
  desktop_lcp_ms: 1200,
  desktop_cls: 0.02,
  desktop_inp_ms: 90,
  fetched_at: '2026-06-15 03:00:00',
  created_at: '2026-06-15 03:00:05',
};

describe('SitePerformancePanel', () => {
  beforeEach(() => {
    setAccessToken('fake');
  });

  it('renders the mobile and desktop scores from the latest snapshot', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/sites/:id/performance', () => latestResponse(SNAPSHOT)),
    );
    renderPanel();
    expect(await screen.findByText('82')).toBeInTheDocument();
    expect(screen.getByText('96')).toBeInTheDocument();
  });

  it('shows the not-measured state when latest is null', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/sites/:id/performance', () => latestResponse(null)),
    );
    renderPanel();
    expect(await screen.findByText(/not yet measured/i)).toBeInTheDocument();
  });

  it('fires a measure when "Measure now" is clicked', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/sites/:id/performance', () => latestResponse(null)),
    );
    renderPanel();
    const btn = await screen.findByRole('button', { name: /measure now/i });
    await userEvent.click(btn);
    // POST handler returns 202; mutation enters pending then polling state.
    await waitFor(() => expect(btn).toBeDisabled());
  });
});
