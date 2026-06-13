import { describe, it, expect } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useJobsList, jobsListPollInterval } from '@/lib/queries/useJobsList';
import type { JobsListResponse } from '@/types/api';
import React from 'react';

function makeWrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

const BASE_JOB = {
  id: 42,
  kind: 'plugin_update' as const,
  scheduled_count: 3,
  skipped_count: 0,
  succeeded_count: 1,
  failed_count: 1,
  cancelled_count: 0,
  queued_count: 1,
  started_count: 0,
  state: 'in_progress' as const,
  started_at: '2026-06-09 21:00:00',
  completed_at: null,
  created_at: '2026-06-09 20:59:15',
};

function listResponse(state: 'queued' | 'in_progress' | 'completed' | 'partial'): JobsListResponse {
  return {
    jobs: [{ ...BASE_JOB, state }],
    total: 1,
    page: 1,
    per_page: 20,
    generated_at: '2026-06-09 21:30:00',
  };
}

describe('useJobsList', () => {
  it('fetches and parses the list shape (default MSW handler)', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useJobsList('all', 1), { wrapper: makeWrapper(qc) });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.jobs).toHaveLength(1);
    expect(result.current.data?.jobs[0].id).toBe(42);
    expect(result.current.data?.jobs[0].state).toBe('in_progress');
    expect(result.current.data?.total).toBe(1);
  });

  it('jobsListPollInterval returns 10s while any job is queued/in_progress (guardrail #9)', () => {
    expect(jobsListPollInterval(listResponse('in_progress'))).toBe(10_000);
    expect(jobsListPollInterval(listResponse('queued'))).toBe(10_000);
  });

  it('jobsListPollInterval stops polling when all jobs terminal', () => {
    expect(jobsListPollInterval(listResponse('completed'))).toBe(false);
    expect(jobsListPollInterval(listResponse('partial'))).toBe(false);
    expect(jobsListPollInterval(undefined)).toBe(false);
  });
});
