import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { retryItemResponseSchema, type RetryItemResponse } from '@/types/api';

export interface RetryItemVariables {
  jobId: number;
  itemId: number;
}

/**
 * P2.9 — POST /jobs/{id}/items/{itemId}/retry. One-click per-item retry
 * (guardrail #21 — no confirm dialog). Same invalidation set as
 * useCancelJob (guardrail #10).
 */
export function useRetryItem() {
  const queryClient = useQueryClient();

  return useMutation<RetryItemResponse, Error, RetryItemVariables>({
    mutationFn: async ({ jobId, itemId }) => {
      const data = await apiClient.post<unknown>(`/jobs/${jobId}/items/${itemId}/retry`);
      return retryItemResponseSchema.parse(data);
    },
    onSuccess: (_data, { jobId }) => {
      queryClient.invalidateQueries({ queryKey: ['job', jobId] });
      queryClient.invalidateQueries({ queryKey: ['jobs'] });
      queryClient.invalidateQueries({ queryKey: ['jobsCount'] });
    },
  });
}
