import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import SiteDetail from '@/routes/SiteDetail';
import { resetMockSites, mockSites } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function renderAt(id: number) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[`/sites/${id}`]}>
        <Routes>
          <Route path="/sites/:id" element={<SiteDetail />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('SiteDetail', () => {
  beforeEach(() => {
    resetMockSites();
    setAccessToken('fake');
  });

  it('shows pending state and a connecting message', async () => {
    mockSites.push({
      id: 1, url: 'https://x.test', label: 'X', status: 'pending',
      last_contact_at: null, last_error: null, created_at: '2026-05-11 00:00:00',
    });
    renderAt(1);
    expect(await screen.findByText(/Connecting/i)).toBeInTheDocument();
    expect(await screen.findByText('https://x.test')).toBeInTheDocument();
  });

  it('shows active state with last_contact_at', async () => {
    mockSites.push({
      id: 1, url: 'https://x.test', label: '', status: 'active',
      last_contact_at: '2026-05-11 00:07:00', last_error: null, created_at: '2026-05-11 00:00:00',
    });
    renderAt(1);
    expect(await screen.findByText(/Connected/i)).toBeInTheDocument();
  });

  it('shows error state with last_error', async () => {
    mockSites.push({
      id: 1, url: 'https://x.test', label: '', status: 'error',
      last_contact_at: null, last_error: 'Challenge signature invalid',
      created_at: '2026-05-11 00:00:00',
    });
    renderAt(1);
    expect(await screen.findByText('Challenge signature invalid')).toBeInTheDocument();
  });

  it('shows a not-found message on 404', async () => {
    renderAt(999);
    await waitFor(() => expect(screen.getByText(/not found/i)).toBeInTheDocument());
  });
});
