import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { updateAllConnectorsResponseSchema, type UpdateAllConnectorsResponse } from '@/types/api';

/**
 * POST /overview/update-connectors — enqueue a connector self-update on every
 * active site below the latest release. Invalidates ['overview'] + ['sites'].
 */
export function useUpdateAllConnectors() {
  const queryClient = useQueryClient();

  return useMutation<UpdateAllConnectorsResponse, Error, void>({
    mutationFn: async () => {
      const data = await apiClient.post<unknown>('/overview/update-connectors');
      return updateAllConnectorsResponseSchema.parse(data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['overview'] });
      queryClient.invalidateQueries({ queryKey: ['sites'] });
    },
  });
}
