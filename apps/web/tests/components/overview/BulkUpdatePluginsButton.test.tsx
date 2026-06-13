import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { BulkUpdatePluginsButton } from '@/components/overview/BulkUpdatePluginsButton';

function renderBtn(pendingCount: number) {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/overview']}>
        <Routes>
          <Route path="/overview" element={<BulkUpdatePluginsButton pendingCount={pendingCount} />} />
          <Route path="/jobs/:id" element={<div>JOB DETAIL PROBE</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('BulkUpdatePluginsButton', () => {
  it('rendersIdleStateWithDynamicCount', () => {
    renderBtn(47);
    expect(
      screen.getByRole('button', { name: /bulk update plugins.*47/i }),
    ).toBeInTheDocument();
  });

  it('hiddenWhenPendingCountZero', () => {
    renderBtn(0);
    expect(
      screen.queryByRole('button', { name: /bulk update plugins/i }),
    ).not.toBeInTheDocument();
  });

  it('opensConfirmDialogOnClick', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/overview/pending-plugin-updates', () =>
        HttpResponse.json({
          pending_updates: [
            { site_id: 1, site_label: 'SmartCoding', slug: 'akismet', plugin_name: 'Akismet', current_version: '5.3', target_version: '5.3.1' },
          ],
          generated_at: '2026-06-09 23:00:00',
        }),
      ),
    );

    renderBtn(1);
    fireEvent.click(screen.getByRole('button', { name: /bulk update plugins.*1/i }));

    await waitFor(() => {
      expect(
        screen.getByText(/bulk update 1 plugins across 1 sites\?/i),
      ).toBeInTheDocument();
    });
  });

  it('showsPendingLabelWhilePostInFlight', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/overview/pending-plugin-updates', () =>
        HttpResponse.json({
          pending_updates: [
            { site_id: 1, site_label: 'SC', slug: 'akismet', plugin_name: 'Akismet', current_version: '5.3', target_version: '5.3.1' },
          ],
          generated_at: '2026-06-09 23:00:00',
        }),
      ),
      http.post('*/wp-json/defyn/v1/overview/bulk-update-plugins', async () => {
        await new Promise((r) => setTimeout(r, 40));
        return HttpResponse.json(
          {
            job_id: 42,
            scheduled_count: 1,
            skipped_count: 0,
            scheduled_pairs: [{ site_id: 1, slug: 'akismet' }],
            skipped_pairs: [],
            scheduled_at: '2026-06-09 23:15:42',
          },
          { status: 202 },
        );
      }),
    );

    renderBtn(1);
    fireEvent.click(screen.getByRole('button', { name: /bulk update plugins.*1/i }));
    await waitFor(() =>
      expect(screen.getByText(/bulk update 1 plugins across 1 sites\?/i)).toBeInTheDocument(),
    );
    fireEvent.click(screen.getByRole('button', { name: /bulk update 1 plugins/i }));

    await waitFor(() => {
      expect(screen.getByText(/scheduling 1 updates/i)).toBeInTheDocument();
    });
  });

  it('navigatesToJobDetailOnSuccess', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/overview/pending-plugin-updates', () =>
        HttpResponse.json({
          pending_updates: [
            { site_id: 1, site_label: 'SmartCoding', slug: 'akismet', plugin_name: 'Akismet', current_version: '5.3', target_version: '5.3.1' },
          ],
          generated_at: '2026-06-09 23:00:00',
        }),
      ),
      http.post('*/wp-json/defyn/v1/overview/bulk-update-plugins', () =>
        HttpResponse.json(
          {
            job_id: 42,
            scheduled_count: 1,
            skipped_count: 0,
            scheduled_pairs: [{ site_id: 1, slug: 'akismet' }],
            skipped_pairs: [],
            scheduled_at: '2026-06-09 23:15:42',
          },
          { status: 202 },
        ),
      ),
    );

    renderBtn(1);
    fireEvent.click(screen.getByRole('button', { name: /bulk update plugins.*1/i }));

    await waitFor(() =>
      expect(screen.getByText(/bulk update 1 plugins across 1 sites\?/i)).toBeInTheDocument(),
    );
    fireEvent.click(screen.getByRole('button', { name: /bulk update 1 plugins/i }));

    await waitFor(() => {
      expect(screen.getByText('JOB DETAIL PROBE')).toBeInTheDocument();
    });
  });
});
