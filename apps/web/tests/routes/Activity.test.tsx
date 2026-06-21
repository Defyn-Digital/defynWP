import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import Activity from '@/routes/Activity';

function renderActivity() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/activity']}>
        <Activity />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Activity page (post-shell layout)', () => {
  it('renders the PageHeader heading', () => {
    renderActivity();
    expect(screen.getByRole('heading', { name: /activity/i })).toBeInTheDocument();
  });

  it('shows a styled empty state when there are no events', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/activity', () =>
        HttpResponse.json({ events: [], total: 0, page: 1, per_page: 100 }),
      ),
    );
    renderActivity();
    await waitFor(() =>
      expect(screen.getByText(/no events match your filters/i)).toBeInTheDocument(),
    );
  });
});
