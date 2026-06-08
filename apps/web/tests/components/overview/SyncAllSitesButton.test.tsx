import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { SyncAllSitesButton } from '@/components/overview/SyncAllSitesButton';

function renderButton(totalSites: number) {
  const qc = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  });
  return render(
    <QueryClientProvider client={qc}>
      <SyncAllSitesButton totalSites={totalSites} />
    </QueryClientProvider>,
  );
}

describe('SyncAllSitesButton', () => {
  it('renders the idle "Sync all sites" trigger', () => {
    renderButton(12);
    expect(screen.getByRole('button', { name: /sync all sites/i })).toBeInTheDocument();
    // Dialog should not be visible until clicked.
    expect(screen.queryByRole('button', { name: /sync all 12 sites/i })).not.toBeInTheDocument();
  });

  it('opens the confirm dialog on click', () => {
    renderButton(12);
    fireEvent.click(screen.getByRole('button', { name: /sync all sites/i }));
    expect(screen.getByRole('button', { name: /sync all 12 sites/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^cancel$/i })).toBeInTheDocument();
  });

  it('shows a pending label while the mutation is in flight', async () => {
    // Add a small delay so the in-flight state is observable.
    server.use(
      http.post('*/wp-json/defyn/v1/overview/sync-all', async () => {
        await new Promise((r) => setTimeout(r, 40));
        return HttpResponse.json(
          { scheduled_count: 12, site_ids: Array.from({ length: 12 }, (_, i) => i + 1), scheduled_at: '2026-06-08 09:30:42' },
          { status: 202 },
        );
      }),
    );

    renderButton(12);
    fireEvent.click(screen.getByRole('button', { name: /sync all sites/i }));
    fireEvent.click(screen.getByRole('button', { name: /sync all 12 sites/i }));

    await waitFor(() => {
      expect(screen.getByText(/syncing 12 sites/i)).toBeInTheDocument();
    });
  });
});
