import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import SiteAdd from '@/routes/SiteAdd';
import { resetMockSites } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function renderWith(initialPath = '/sites/add') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[initialPath]}>
        <Routes>
          <Route path="/sites/add" element={<SiteAdd />} />
          <Route path="/sites/:id" element={<div data-testid="site-detail-mock" />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('SiteAdd', () => {
  beforeEach(() => {
    resetMockSites();
    setAccessToken('fake');
  });

  it('renders the form fields', () => {
    renderWith();
    expect(screen.getByLabelText(/URL/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Label/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Code/i)).toBeInTheDocument();
  });

  it('shows a field error for an http (non-https) URL', async () => {
    const user = userEvent.setup();
    renderWith();
    await user.type(screen.getByLabelText(/URL/i), 'http://insecure.test');
    await user.type(screen.getByLabelText(/Code/i), 'ABCDEFGH2345');
    await user.click(screen.getByRole('button', { name: /Add Site/i }));
    expect(await screen.findByText(/URL must start with https/i)).toBeInTheDocument();
  });

  it('navigates to the detail route on success', async () => {
    const user = userEvent.setup();
    renderWith();
    await user.type(screen.getByLabelText(/URL/i), 'https://example.test');
    await user.type(screen.getByLabelText(/Label/i), 'My site');
    await user.type(screen.getByLabelText(/Code/i), 'ABCDEFGH2345');
    await user.click(screen.getByRole('button', { name: /Add Site/i }));
    expect(await screen.findByTestId('site-detail-mock')).toBeInTheDocument();
  });
});
