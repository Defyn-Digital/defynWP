import { beforeEach, describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { IncidentHistoryPanel } from '@/components/sites/IncidentHistoryPanel';
import { setAccessToken } from '@/lib/apiClient';

function wrap(siteId: number) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <IncidentHistoryPanel siteId={siteId} />
    </QueryClientProvider>,
  );
}

describe('IncidentHistoryPanel', () => {
  beforeEach(() => {
    setAccessToken('fake');
  });

  it('shows empty state when no incidents recorded', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/sites/:id/incidents', () => {
        return HttpResponse.json({ data: { incidents: [] }, error: null });
      }),
    );
    wrap(1);
    await screen.findByText(/no incidents recorded/i);
  });

  it('shows an ongoing incident row', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/sites/:id/incidents', () => {
        return HttpResponse.json({
          data: {
            incidents: [
              {
                id: 1,
                site_id: 1,
                started_at: '2026-06-14T01:00:00Z',
                ended_at: null,
                duration_seconds: null,
                last_error: 'Connection refused',
                created_at: '2026-06-14T01:00:01Z',
              },
            ],
          },
          error: null,
        });
      }),
    );
    wrap(1);
    await screen.findByText(/ongoing/i);
    expect(screen.getByText(/2026-06-14T01:00:00Z/)).toBeInTheDocument();
  });

  it('shows a closed incident row with humanized duration', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/sites/:id/incidents', () => {
        return HttpResponse.json({
          data: {
            incidents: [
              {
                id: 2,
                site_id: 1,
                started_at: '2026-06-14T02:00:00Z',
                ended_at: '2026-06-14T02:35:00Z',
                duration_seconds: 2100,
                last_error: null,
                created_at: '2026-06-14T02:00:01Z',
              },
            ],
          },
          error: null,
        });
      }),
    );
    wrap(1);
    // Verify start → end row is rendered
    await screen.findByText(/2026-06-14T02:00:00Z/);
    expect(screen.getByText(/2026-06-14T02:35:00Z/)).toBeInTheDocument();
    // 2100s = 35m
    expect(screen.getByText(/35m/)).toBeInTheDocument();
  });
});
