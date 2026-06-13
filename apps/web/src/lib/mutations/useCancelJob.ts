import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { cancelJobResponseSchema, type CancelJobResponse } from '@/types/api';

/**
 * P2.9 — POST /jobs/{id}/cancel. On success invalidates ['job', id] +
 * ['jobs'] (prefix — every status/page key) + ['jobsCount']. NOT
 * ['sites'] — per-site state hasn't changed (guardrail #10).
 */
export function useCancelJob() {
  const queryClient = useQueryClient();

  return useMutation<CancelJobResponse, Error, number>({
    mutationFn: async (jobId) => {
      const data = await apiClient.post<unknown>(`/jobs/${jobId}/cancel`);
      return cancelJobResponseSchema.parse(data);
    },
    onSuccess: (_data, jobId) => {
      queryClient.invalidateQueries({ queryKey: ['job', jobId] });
      queryClient.invalidateQueries({ queryKey: ['jobs'] });
      queryClient.invalidateQueries({ queryKey: ['jobsCount'] });
    },
  });
}
