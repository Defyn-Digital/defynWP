import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, beforeEach } from 'vitest';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/test/setup';
import { SiteAnalyticsPanel } from '@/components/sites/SiteAnalyticsPanel';
import { setAccessToken } from '@/lib/apiClient';

function renderPanel(siteId = 7) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<SiteAnalyticsPanel siteId={siteId} />, { wrapper });
}

function analyticsResponse(latest: unknown, ga4PropertyId: string | null) {
  return HttpResponse.json({ data: { latest, ga4_property_id: ga4PropertyId }, error: null });
}

const SNAPSHOT = {
  id: 11,
  site_id: 7,
  period_start: '2026-06-01',
  period_end: '2026-06-30',
  sessions: 1200,
  total_users: 900,
  screen_page_views: 3400,
  avg_session_duration: 95,
  top_pages: [{ path: '/', title: 'Home', views: 800 }],
  channels: [{ channel: 'Direct', sessions: 600 }],
  fetched_at: '2026-06-30 03:00:00',
};

describe('SiteAnalyticsPanel', () => {
  beforeEach(() => {
    setAccessToken('fake');
  });

  it('renders a property-ID input and Save when not connected', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/sites/:id/analytics', () => analyticsResponse(null, null)),
    );
    renderPanel();
    expect(await screen.findByLabelText(/ga4 property id/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /save/i })).toBeInTheDocument();
  });

  it('renders the property and "Refresh analytics now" when connected', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/sites/:id/analytics', () => analyticsResponse(SNAPSHOT, '123456789')),
    );
    renderPanel();
    expect(await screen.findByRole('button', { name: /refresh analytics now/i })).toBeInTheDocument();
    expect(screen.getByText(/123456789/)).toBeInTheDocument();
  });

  it('fires the set mutation when Save is clicked with a property value', async () => {
    let posted: { ga4_property_id?: string } | null = null;
    server.use(
      http.get('*/wp-json/defyn/v1/sites/:id/analytics', () => analyticsResponse(null, null)),
      http.post('*/wp-json/defyn/v1/sites/:id/ga4-property', async ({ request }) => {
        posted = (await request.json()) as { ga4_property_id?: string };
        return HttpResponse.json({ data: { ga4_property_id: posted.ga4_property_id }, error: null });
      }),
    );
    renderPanel();
    const input = await screen.findByLabelText(/ga4 property id/i);
    await userEvent.type(input, '987654321');
    await userEvent.click(screen.getByRole('button', { name: /save/i }));
    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted!.ga4_property_id).toBe('987654321');
  });
});
