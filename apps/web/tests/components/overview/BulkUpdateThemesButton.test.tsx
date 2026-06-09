import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BulkUpdateThemesButton } from '@/components/overview/BulkUpdateThemesButton';

function renderBtn(pendingCount: number) {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <BulkUpdateThemesButton pendingCount={pendingCount} />
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
});
