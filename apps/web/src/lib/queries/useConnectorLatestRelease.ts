import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { connectorLatestReleaseSchema } from '@/types/api';

/**
 * GET /connector/latest-release — the latest connector version published to
 * GitHub {version, package_url, sha256}. Cached a while; a failure (GitHub
 * unreachable) just means the "update available" UI stays hidden.
 */
export function useConnectorLatestRelease() {
  return useQuery({
    queryKey: ['connector', 'latest-release'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>('/connector/latest-release');
      return connectorLatestReleaseSchema.parse(data);
    },
    staleTime: 15 * 60 * 1000,
    retry: false,
  });
}
