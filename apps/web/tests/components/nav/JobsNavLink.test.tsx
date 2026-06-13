import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { JobsNavLink } from '@/components/nav/JobsNavLink';

function renderLink() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <JobsNavLink />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('JobsNavLink', () => {
  it('shows the badge when active count > 0 (default MSW fixture has 1 active job)', async () => {
    renderLink();

    expect(screen.getByRole('link', { name: /jobs/i })).toHaveAttribute('href', '/jobs');
    await waitFor(() => expect(screen.getByTestId('jobs-badge')).toHaveTextContent('1'));
  });

  it('hides the badge when active count is 0 (guardrail #22)', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/jobs', () =>
        HttpResponse.json({
          jobs: [],
          total: 0,
          page: 1,
          per_page: 1,
          generated_at: '2026-06-09 21:30:00',
        }),
      ),
    );

    renderLink();

    // Give the query time to resolve, then assert no badge AND no "(0)".
    await waitFor(() => expect(screen.getByRole('link', { name: /jobs/i })).toBeInTheDocument());
    await waitFor(() => expect(screen.queryByTestId('jobs-badge')).not.toBeInTheDocument());
    expect(screen.queryByText(/\(0\)/)).not.toBeInTheDocument();
  });
});
