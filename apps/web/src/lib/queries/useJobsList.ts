import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { jobsListResponseSchema, type JobsListResponse } from '@/types/api';

export type JobsStatusFilter = 'active' | 'completed' | 'all';

/**
 * P2.9 — pure poll-interval decision, exported for direct unit testing
 * (guardrail #9): 10s while ANY job is non-terminal, otherwise stop.
 */
export function jobsListPollInterval(data: JobsListResponse | undefined): number | false {
  return data?.jobs.some((j) => j.state === 'queued' || j.state === 'in_progress') ? 10_000 : false;
}

/**
 * P2.9 — jobs list with adaptive polling. Path is `/jobs` (trap #27 —
 * apiClient prepends /api/defyn/v1; the spec's `/overview/jobs` example
 * was wrong). TanStack v5: refetchInterval receives the Query object
 * (trap #28).
 */
export function useJobsList(status: JobsStatusFilter, page: number) {
  return useQuery({
    queryKey: ['jobs', status, page],
    queryFn: async () => {
      const data = await apiClient.get<unknown>(`/jobs?status=${status}&page=${page}&per_page=20`);
      return jobsListResponseSchema.parse(data);
    },
    refetchInterval: (query) =>
      jobsListPollInterval(query.state.data as JobsListResponse | undefined),
    refetchIntervalInBackground: false,
    staleTime: 5_000,
  });
}
