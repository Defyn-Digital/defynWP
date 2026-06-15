import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { ScanAllSitesSecurityButton } from '@/components/security/ScanAllSitesSecurityButton';

function wrap() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('ScanAllSitesSecurityButton', () => {
  it('is disabled when there are no sites', () => {
    render(<ScanAllSitesSecurityButton totalSites={0} />, { wrapper: wrap() });
    expect(screen.getByRole('button', { name: /scan all/i })).toBeDisabled();
  });

  it('opens the confirm dialog and fires the scan on confirm', async () => {
    render(<ScanAllSitesSecurityButton totalSites={3} />, { wrapper: wrap() });
    await userEvent.click(screen.getByRole('button', { name: /scan all/i }));
    // confirm dialog appears
    expect(await screen.findByText(/scan all 3 sites now/i)).toBeInTheDocument();
    // confirm → POST /security/scan-all (MSW default 200) without error
    const confirm = screen.getAllByRole('button', { name: /scan all 3 sites/i }).pop()!;
    await userEvent.click(confirm);
    await waitFor(() => expect(screen.queryByText(/scan all 3 sites now/i)).not.toBeInTheDocument());
  });
});
