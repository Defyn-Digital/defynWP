import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { connectorUpdateResponseSchema, type ConnectorUpdateResponse } from '@/types/api';

/**
 * POST /sites/{id}/connector/update — upgrade this site's connector to the
 * latest GitHub release. On success invalidates the site query; the connector
 * version refreshes on the next /status sync after the self-update completes.
 */
export function useUpdateSiteConnector(siteId: number) {
  const queryClient = useQueryClient();

  return useMutation<ConnectorUpdateResponse, Error, void>({
    mutationFn: async () => {
      const data = await apiClient.post<unknown>(`/sites/${siteId}/connector/update`);
      return connectorUpdateResponseSchema.parse(data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sites', siteId] });
    },
  });
}
