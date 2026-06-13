import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import Jobs from '@/routes/Jobs';

function renderJobs(initialEntry = '/jobs') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <Routes>
          <Route path="/jobs" element={<Jobs />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Jobs', () => {
  it('renders the list from the default MSW handler', async () => {
    renderJobs();

    await waitFor(() => {
      expect(screen.getByText(/plugin update — 3 scheduled/i)).toBeInTheDocument();
    });
    expect(screen.getByTestId('job-state-chip')).toHaveTextContent('in progress');
  });

  it('status filter chips switch the query (Completed shows the empty state)', async () => {
    renderJobs();

    await waitFor(() => expect(screen.getByText(/plugin update — 3 scheduled/i)).toBeInTheDocument());

    // Default MSW handler returns [] for status=completed.
    fireEvent.click(screen.getByRole('button', { name: /^completed$/i }));

    await waitFor(() => {
      expect(screen.getByText(/no jobs yet/i)).toBeInTheDocument();
    });
  });

  it('paginates via Prev/Next when total exceeds per_page', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/jobs', ({ request }) => {
        const url = new URL(request.url);
        const page = Number(url.searchParams.get('page') ?? '1');
        return HttpResponse.json({
          jobs: [
            {
              id: page === 1 ? 42 : 41,
              kind: 'plugin_update',
              scheduled_count: page === 1 ? 12 : 5,
              skipped_count: 0,
              succeeded_count: 0,
              failed_count: 0,
              cancelled_count: 0,
              queued_count: page === 1 ? 12 : 5,
              started_count: 0,
              state: 'queued',
              started_at: null,
              completed_at: null,
              created_at: '2026-06-09 20:59:15',
            },
          ],
          total: 25,
          page,
          per_page: 20,
          generated_at: '2026-06-09 21:30:00',
        });
      }),
    );

    renderJobs();

    await waitFor(() => expect(screen.getByText(/page 1 of 2/i)).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /prev/i })).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: /next/i }));

    await waitFor(() => expect(screen.getByText(/page 2 of 2/i)).toBeInTheDocument());
    expect(screen.getByText(/plugin update — 5 scheduled/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /next/i })).toBeDisabled();
  });
});
