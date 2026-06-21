import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import Overview from '@/routes/Overview';

function renderOverview() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/overview']}>
        <Overview />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Overview header (post-shell)', () => {
  it('shows the PageHeader title and no in-page Monitoring/Security/Settings nav links', async () => {
    renderOverview();
    await waitFor(() => expect(screen.getByRole('heading', { name: /overview/i })).toBeInTheDocument());
    expect(screen.queryByRole('link', { name: /^monitoring$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /^security$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /^settings$/i })).not.toBeInTheDocument();
  });
});
