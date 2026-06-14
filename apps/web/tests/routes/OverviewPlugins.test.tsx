import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import OverviewPlugins from '@/routes/OverviewPlugins';

const POPULATED = {
  pending_updates: [
    { site_id: 1, site_label: 'SmartCoding', slug: 'akismet',   plugin_name: 'Akismet',   current_version: '5.3',    target_version: '5.3.1' },
    { site_id: 1, site_label: 'SmartCoding', slug: 'elementor', plugin_name: 'Elementor', current_version: '3.18.2', target_version: '4.0.0' }, // MAJOR
    { site_id: 2, site_label: 'AcmeBlog',    slug: 'jetpack',   plugin_name: 'Jetpack',   current_version: '13.1',   target_version: '13.2' },
  ],
  generated_at: '2026-06-14 10:00:00',
};

function usePopulated() {
  server.use(
    http.get('*/wp-json/defyn/v1/overview/pending-plugin-updates', () =>
      HttpResponse.json(POPULATED),
    ),
  );
}

function renderPage() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/overview/plugins']}>
        <Routes>
          <Route path="/overview/plugins" element={<OverviewPlugins />} />
          <Route path="/overview" element={<div>OVERVIEW PROBE</div>} />
          <Route path="/jobs/:id" element={<div>JOB DETAIL PROBE</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('OverviewPlugins', () => {
  it('rendersEmptyStateFromDefaultHandler', async () => {
    // Default MSW handler returns pending_updates: [].
    renderPage();
    await waitFor(() =>
      expect(screen.getByText(/no pending plugin updates across your fleet/i)).toBeInTheDocument(),
    );
    // No footer button in the empty state.
    expect(screen.queryByRole('button', { name: /update .* selected/i })).not.toBeInTheDocument();
  });

  it('rendersPopulatedListGroupedBySiteWithFooterCounts', async () => {
    usePopulated();
    renderPage();
    await waitFor(() =>
      expect(screen.getByRole('checkbox', { name: /akismet/i })).toBeInTheDocument(),
    );
    expect(screen.getByRole('checkbox', { name: /elementor/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /jetpack/i })).toBeInTheDocument();
    expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /update 3 selected/i })).toBeInTheDocument();
  });

  it('backLinkPointsToOverview', async () => {
    usePopulated();
    renderPage();
    await waitFor(() =>
      expect(screen.getByRole('link', { name: /overview/i })).toBeInTheDocument(),
    );
    expect(screen.getByRole('link', { name: /overview/i })).toHaveAttribute('href', '/overview');
  });

  it('uncheckingARowUpdatesTheFooterCounter', async () => {
    usePopulated();
    renderPage();
    await waitFor(() => expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument());
    fireEvent.click(screen.getByRole('checkbox', { name: /akismet/i }));
    expect(screen.getByText(/2 selected of 3 available/i)).toBeInTheDocument();
  });

  it('skipMajorToggleHidesMajorRowsAndReDerivesCounts', async () => {
    usePopulated();
    renderPage();
    await waitFor(() => expect(screen.getByRole('checkbox', { name: /elementor/i })).toBeInTheDocument());
    fireEvent.click(screen.getByRole('checkbox', { name: /skip major bumps/i }));
    expect(screen.queryByRole('checkbox', { name: /elementor/i })).not.toBeInTheDocument();
    expect(screen.getByText(/2 selected of 2 available/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /update 2 selected/i })).toBeInTheDocument();
  });

  it('footerButtonDisabledWhenNothingChecked', async () => {
    usePopulated();
    renderPage();
    await waitFor(() => expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument());
    fireEvent.click(screen.getByRole('checkbox', { name: /akismet/i }));
    fireEvent.click(screen.getByRole('checkbox', { name: /elementor/i }));
    fireEvent.click(screen.getByRole('checkbox', { name: /jetpack/i }));
    const footerBtn = screen.getByRole('button', { name: /update 0 selected/i });
    expect(footerBtn).toBeDisabled();
  });

  it('footerButtonOpensGateThenConfirmNavigatesToJobDetail', async () => {
    usePopulated();
    // POST returns job_id 77 → navigate to /jobs/77.
    server.use(
      http.post('*/wp-json/defyn/v1/overview/bulk-update-plugins', async ({ request }) => {
        const body = (await request.json()) as { updates: Array<{ site_id: number; slug: string }> };
        return HttpResponse.json(
          {
            job_id: 77,
            scheduled_count: body.updates.length,
            skipped_count: 0,
            scheduled_pairs: body.updates,
            skipped_pairs: [],
            scheduled_at: '2026-06-14 10:00:42',
          },
          { status: 202 },
        );
      }),
    );
    renderPage();
    await waitFor(() => expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument());

    // Footer button opens the gate (NOT a re-listing).
    fireEvent.click(screen.getByRole('button', { name: /update 3 selected/i }));
    await waitFor(() =>
      expect(screen.getByText(/update 3 plugins across 2 sites\?/i)).toBeInTheDocument(),
    );

    // Confirm in the gate → POST → navigate.
    fireEvent.click(screen.getByRole('button', { name: /^update 3 plugins$/i }));
    await waitFor(() => expect(screen.getByText('JOB DETAIL PROBE')).toBeInTheDocument());
  });

  it('gateCancelClosesWithoutNavigating', async () => {
    usePopulated();
    renderPage();
    await waitFor(() => expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /update 3 selected/i }));
    await waitFor(() =>
      expect(screen.getByText(/update 3 plugins across 2 sites\?/i)).toBeInTheDocument(),
    );
    fireEvent.click(screen.getByRole('button', { name: /^cancel$/i }));
    await waitFor(() =>
      expect(screen.queryByText(/update 3 plugins across 2 sites\?/i)).not.toBeInTheDocument(),
    );
    // Still on the page, not navigated.
    expect(screen.queryByText('JOB DETAIL PROBE')).not.toBeInTheDocument();
    expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument();
  });
});
