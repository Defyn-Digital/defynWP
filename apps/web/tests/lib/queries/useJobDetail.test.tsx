import { describe, it, expect } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useJobDetail, jobDetailPollInterval } from '@/lib/queries/useJobDetail';
import type { JobDetailResponse, JobItem } from '@/types/api';
import React from 'react';

function makeWrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

function makeItem(state: JobItem['state']): JobItem {
  return {
    id: 201,
    site_id: 1,
    site_label: 'SmartCoding',
    resource_slug: 'akismet',
    resource_name: 'Akismet Anti-Spam',
    current_version: '5.3',
    target_version: '5.3.1',
    state,
    error_message: null,
    started_at: null,
    completed_at: null,
    created_at: '2026-06-09 20:59:15',
  };
}

function detailResponse(states: Array<JobItem['state']>): JobDetailResponse {
  return {
    job: {
      id: 42,
      kind: 'plugin_update',
      scheduled_count: states.length,
      skipped_count: 0,
      succeeded_count: 0,
      failed_count: 0,
      cancelled_count: 0,
      queued_count: states.length,
      started_count: 0,
      state: 'queued',
      started_at: null,
      completed_at: null,
      created_at: '2026-06-09 20:59:15',
    },
    items: states.map((s) => makeItem(s)),
    generated_at: '2026-06-09 21:30:00',
  };
}

describe('useJobDetail', () => {
  it('fetches and parses the detail shape (default MSW handler)', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useJobDetail(42), { wrapper: makeWrapper(qc) });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.job.id).toBe(42);
    expect(result.current.data?.items).toHaveLength(3);
    expect(result.current.data?.items[1].state).toBe('failed');
    expect(result.current.data?.items[1].error_message).toBe('Could not copy file.');
  });

  it('jobDetailPollInterval returns 5s while any item is queued/started (guardrail #9)', () => {
    expect(jobDetailPollInterval(detailResponse(['succeeded', 'queued']))).toBe(5_000);
    expect(jobDetailPollInterval(detailResponse(['started']))).toBe(5_000);
  });

  it('jobDetailPollInterval stops polling when all items terminal', () => {
    expect(jobDetailPollInterval(detailResponse(['succeeded', 'failed', 'cancelled']))).toBe(false);
    expect(jobDetailPollInterval(undefined)).toBe(false);
  });
});
