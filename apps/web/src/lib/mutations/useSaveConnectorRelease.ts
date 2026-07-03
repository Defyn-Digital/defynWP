import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';

export interface ConnectorReleaseInput {
  version?: string;
  package_url?: string;
  sha256?: string;
}

/**
 * POST /settings/connector-release — set (or clear) the connector release the
 * dashboard offers via "Update connector"/"Update all connectors". Sidesteps
 * the server-side GitHub lookup. Send all-blank to clear.
 */
export function useSaveConnectorRelease() {
  const qc = useQueryClient();
  return useMutation<unknown, Error, ConnectorReleaseInput>({
    mutationFn: (body) => apiClient.post<unknown>('/settings/connector-release', body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['settings'] });
      qc.invalidateQueries({ queryKey: ['connector', 'latest-release'] });
    },
  });
}
