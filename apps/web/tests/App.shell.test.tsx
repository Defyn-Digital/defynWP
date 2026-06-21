import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthContext } from '@/lib/auth';
import App from '@/App';

const authValue = {
  status: 'authenticated' as const,
  user: { id: 1, email: 'pradeep@defyn.com.au', display_name: 'Pradeep' },
  login: async () => {},
  logout: async () => {},
};

describe('App shell wiring', () => {
  it('renders authed routes inside the sidebar shell', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={qc}>
        <AuthContext.Provider value={authValue}>
          <MemoryRouter initialEntries={['/overview']}>
            <App />
          </MemoryRouter>
        </AuthContext.Provider>
      </QueryClientProvider>,
    );
    // The shell's Sites link is present on every authed route.
    await waitFor(() => expect(screen.getByRole('link', { name: /sites/i })).toBeInTheDocument());
  });
});
