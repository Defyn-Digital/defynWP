import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Insights } from '@/routes/Insights';

function renderInsights() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter><Insights /></MemoryRouter>
    </QueryClientProvider>
  );
}

describe('Insights page', () => {
  it('renders both sections worst-first with empty-state rows', async () => {
    renderInsights();
    await waitFor(() => expect(screen.getByTestId('kpi-slow-sites')).toHaveTextContent('1'));
    expect(screen.getByTestId('kpi-connected')).toHaveTextContent('1/3');

    // Performance worst-first: Bravo (42) before Alpha (80) in DOM order.
    const perfText = document.body.textContent ?? '';
    expect(perfText.indexOf('Bravo')).toBeLessThan(perfText.indexOf('Alpha'));

    // Empty states.
    expect(screen.getByText('Not yet measured')).toBeInTheDocument();
    expect(screen.getByText('Not connected — add a GA4 Property ID')).toBeInTheDocument();
    expect(screen.getByText('Connected — no data yet')).toBeInTheDocument();

    // Row links to site detail.
    const alphaLink = screen.getAllByRole('link', { name: 'Alpha' })[0];
    expect(alphaLink).toHaveAttribute('href', '/sites/1');
  });
});
