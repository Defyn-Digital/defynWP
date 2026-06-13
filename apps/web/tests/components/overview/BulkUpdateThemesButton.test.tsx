import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { BulkUpdateThemesButton } from '@/components/overview/BulkUpdateThemesButton';

function renderBtn(pendingCount: number) {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/overview']}>
        <Routes>
          <Route path="/overview" element={<BulkUpdateThemesButton pendingCount={pendingCount} />} />
          <Route path="/jobs/:id" element={<div>JOB DETAIL PROBE</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('BulkUpdateThemesButton', () => {
  it('hiddenWhenPendingCountIsZero', () => {
    renderBtn(0);
    expect(
      screen.queryByRole('button', { name: /bulk update/i }),
    ).not.toBeInTheDocument();
  });

  it('visibleWithCountWhenPendingCountGreaterThanZero', () => {
    renderBtn(12);
    expect(
      screen.getByRole('button', { name: /bulk update themes.*12/i }),
    ).toBeInTheDocument();
  });

  it('navigatesToJobDetailOnSuccess', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/overview/pending-theme-updates', () =>
        HttpResponse.json({
          pending_updates: [
            { site_id: 1, site_label: 'SmartCoding', slug: 'astra', theme_name: 'Astra', current_version: '4.6.3', target_version: '4.7.0' },
          ],
          generated_at: '2026-06-09 23:00:00',
        }),
      ),
      http.post('*/wp-json/defyn/v1/overview/bulk-update-themes', () =>
        HttpResponse.json(
          {
            job_id: 42,
            scheduled_count: 1,
            skipped_count: 0,
            scheduled_pairs: [{ site_id: 1, slug: 'astra' }],
            skipped_pairs: [],
            scheduled_at: '2026-06-09 23:15:42',
          },
          { status: 202 },
        ),
      ),
    );

    renderBtn(1);
    fireEvent.click(screen.getByRole('button', { name: /bulk update themes.*1/i }));

    await waitFor(() =>
      expect(screen.getByText(/bulk update 1 themes across 1 sites\?/i)).toBeInTheDocument(),
    );
    fireEvent.click(screen.getByRole('button', { name: /bulk update 1 themes/i }));

    // Guardrail #11 — mutation onSuccess navigates to /jobs/{job_id}.
    await waitFor(() => {
      expect(screen.getByText('JOB DETAIL PROBE')).toBeInTheDocument();
    });
  });
});
