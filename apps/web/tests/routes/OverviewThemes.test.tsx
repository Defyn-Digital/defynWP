import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import OverviewThemes from '@/routes/OverviewThemes';

const POPULATED = {
  pending_updates: [
    { site_id: 1, site_label: 'SmartCoding', slug: 'astra',   theme_name: 'Astra',   current_version: '4.6.3',  target_version: '4.7.0' },
    { site_id: 1, site_label: 'SmartCoding', slug: 'kadence', theme_name: 'Kadence', current_version: '1.1.40', target_version: '2.0.0' }, // MAJOR
    { site_id: 2, site_label: 'AcmeBlog',    slug: 'blocksy', theme_name: 'Blocksy', current_version: '2.0.1',  target_version: '2.0.2' },
  ],
  generated_at: '2026-06-14 10:00:00',
};

function usePopulated() {
  server.use(
    http.get('*/wp-json/defyn/v1/overview/pending-theme-updates', () =>
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
      <MemoryRouter initialEntries={['/overview/themes']}>
        <Routes>
          <Route path="/overview/themes" element={<OverviewThemes />} />
          <Route path="/overview" element={<div>OVERVIEW PROBE</div>} />
          <Route path="/jobs/:id" element={<div>JOB DETAIL PROBE</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('OverviewThemes', () => {
  it('rendersEmptyStateFromDefaultHandler', async () => {
    renderPage();
    await waitFor(() =>
      expect(screen.getByText(/no pending theme updates across your fleet/i)).toBeInTheDocument(),
    );
    expect(screen.queryByRole('button', { name: /update .* selected/i })).not.toBeInTheDocument();
  });

  it('rendersPopulatedListGroupedBySiteWithFooterCounts', async () => {
    usePopulated();
    renderPage();
    await waitFor(() =>
      expect(screen.getByRole('checkbox', { name: /astra/i })).toBeInTheDocument(),
    );
    expect(screen.getByRole('checkbox', { name: /kadence/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /blocksy/i })).toBeInTheDocument();
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

  it('skipMajorToggleHidesMajorRowsAndReDerivesCounts', async () => {
    usePopulated();
    renderPage();
    await waitFor(() => expect(screen.getByRole('checkbox', { name: /kadence/i })).toBeInTheDocument());
    fireEvent.click(screen.getByRole('checkbox', { name: /skip major bumps/i }));
    expect(screen.queryByRole('checkbox', { name: /kadence/i })).not.toBeInTheDocument();
    expect(screen.getByText(/2 selected of 2 available/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /update 2 selected/i })).toBeInTheDocument();
  });

  it('footerButtonOpensThemeGateThenConfirmNavigatesToJobDetail', async () => {
    usePopulated();
    server.use(
      http.post('*/wp-json/defyn/v1/overview/bulk-update-themes', async ({ request }) => {
        const body = (await request.json()) as { updates: Array<{ site_id: number; slug: string }> };
        return HttpResponse.json(
          {
            job_id: 88,
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

    fireEvent.click(screen.getByRole('button', { name: /update 3 selected/i }));
    await waitFor(() =>
      expect(screen.getByText(/update 3 themes across 2 sites\?/i)).toBeInTheDocument(),
    );

    fireEvent.click(screen.getByRole('button', { name: /^update 3 themes$/i }));
    await waitFor(() => expect(screen.getByText('JOB DETAIL PROBE')).toBeInTheDocument());
  });
});
