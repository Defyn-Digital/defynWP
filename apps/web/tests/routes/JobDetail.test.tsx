import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import JobDetail from '@/routes/JobDetail';

function renderDetail(jobId = 42) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[`/jobs/${jobId}`]}>
        <Routes>
          <Route path="/jobs/:id" element={<JobDetail />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('JobDetail', () => {
  it('renders the header with kind, id and counts', async () => {
    renderDetail();

    await waitFor(() => {
      expect(screen.getByText(/plugin update — job #42/i)).toBeInTheDocument();
    });
    expect(screen.getByText(/3 scheduled · 1 succeeded · 1 failed · 0 cancelled/i)).toBeInTheDocument();
  });

  it('groups items per site (default fixture: SmartCoding ×2 + AcmeBlog ×1)', async () => {
    renderDetail();

    await waitFor(() => expect(screen.getAllByTestId('job-items-group')).toHaveLength(2));
    expect(screen.getByText(/smartcoding \(2 items\)/i)).toBeInTheDocument();
    expect(screen.getByText(/acmeblog \(1 item\)/i)).toBeInTheDocument();
    expect(screen.getByText('Akismet Anti-Spam')).toBeInTheDocument();
  });

  it('Cancel button enabled (fixture has queued_count 1) and Retry-all enabled (failed_count 1)', async () => {
    renderDetail();

    await waitFor(() => expect(screen.getByRole('button', { name: /^cancel$/i })).toBeEnabled());
    expect(screen.getByRole('button', { name: /retry all/i })).toBeEnabled();
  });

  it('renders the Back to Jobs link', async () => {
    renderDetail();

    await waitFor(() => {
      expect(screen.getByRole('link', { name: /back to jobs/i })).toHaveAttribute('href', '/jobs');
    });
  });
});
