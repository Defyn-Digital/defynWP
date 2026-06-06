import { beforeEach, describe, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { SiteThemesPanel } from '@/components/sites/SiteThemesPanel';
import { mockSiteThemes, resetMockSiteThemes } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function wrap(siteId: number) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <SiteThemesPanel siteId={siteId} />
    </QueryClientProvider>,
  );
}

describe('SiteThemesPanel', () => {
  beforeEach(() => {
    resetMockSiteThemes();
    setAccessToken('fake');
  });

  it('renders empty state when no themes synced yet', async () => {
    wrap(1);
    await waitFor(() => expect(screen.getByText(/Themes/i)).toBeInTheDocument());
    await waitFor(() =>
      expect(screen.getByText(/inventory not yet captured/i)).toBeInTheDocument(),
    );
  });

  it('renders the themes table when data arrives', async () => {
    mockSiteThemes[1] = [
      {
        slug: 'twentytwentyfive',
        name: 'Twenty Twenty-Five',
        version: '1.2',
        parent_slug: null,
        is_active: true,
        update_available: true,
        update_version: '1.3',
        update_state: 'idle',
        last_update_error: null,
        last_update_attempt_at: '2026-06-06 05:00:00',
      },
    ];
    wrap(1);
    await waitFor(() =>
      expect(screen.getByText('Twenty Twenty-Five')).toBeInTheDocument(),
    );
    expect(screen.getByText('Active')).toBeInTheDocument();
  });

  it('renders a Refresh button', async () => {
    wrap(1);
    await waitFor(() =>
      expect(screen.getByLabelText(/Refresh themes/i)).toBeInTheDocument(),
    );
  });
});
