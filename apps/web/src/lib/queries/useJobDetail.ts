import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { jobDetailResponseSchema, type JobDetailResponse } from '@/types/api';

/**
 * P2.9 — pure poll-interval decision, exported for direct unit testing
 * (guardrail #9): 5s while ANY item is queued/started, otherwise stop —
 * the UI freezes at the final state until the operator navigates away.
 */
export function jobDetailPollInterval(data: JobDetailResponse | undefined): number | false {
  return data?.items.some((i) => i.state === 'queued' || i.state === 'started') ? 5_000 : false;
}

export function useJobDetail(id: number) {
  return useQuery({
    queryKey: ['job', id],
    queryFn: async () => {
      const data = await apiClient.get<unknown>(`/jobs/${id}`);
      return jobDetailResponseSchema.parse(data);
    },
    refetchInterval: (query) =>
      jobDetailPollInterval(query.state.data as JobDetailResponse | undefined),
    refetchIntervalInBackground: false,
    staleTime: 2_000,
  });
}
