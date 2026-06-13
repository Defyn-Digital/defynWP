import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { retryFailedResponseSchema, type RetryFailedResponse } from '@/types/api';

/**
 * P2.9 — POST /jobs/{id}/retry-failed. Bulk retry behind the neutral
 * RetryFailedDialog. Same invalidation set as useCancelJob (guardrail #10).
 */
export function useRetryFailed() {
  const queryClient = useQueryClient();

  return useMutation<RetryFailedResponse, Error, number>({
    mutationFn: async (jobId) => {
      const data = await apiClient.post<unknown>(`/jobs/${jobId}/retry-failed`);
      return retryFailedResponseSchema.parse(data);
    },
    onSuccess: (_data, jobId) => {
      queryClient.invalidateQueries({ queryKey: ['job', jobId] });
      queryClient.invalidateQueries({ queryKey: ['jobs'] });
      queryClient.invalidateQueries({ queryKey: ['jobsCount'] });
    },
  });
}
