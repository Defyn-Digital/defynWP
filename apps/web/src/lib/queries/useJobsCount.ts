import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { jobsListResponseSchema } from '@/types/api';

/**
 * P2.9 — active-jobs count for the JobsNavLink badge. Derived from the
 * list endpoint (status=active&per_page=1 — `total` carries the count;
 * trap #32, no dedicated endpoint). Polls every 30s unconditionally
 * (guardrail #9).
 */
export function useJobsCount() {
  return useQuery({
    queryKey: ['jobsCount'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>('/jobs?status=active&page=1&per_page=1');
      return jobsListResponseSchema.parse(data).total;
    },
    refetchInterval: 30_000,
    refetchIntervalInBackground: false,
  });
}
