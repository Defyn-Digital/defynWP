import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { sitePluginsListResponseSchema } from '@/types/api/plugins';

interface UseSitePluginsOptions {
  refetchInterval?: number | false;
}

export function useSitePlugins(siteId: number, opts: UseSitePluginsOptions = {}) {
  return useQuery({
    queryKey: ['sites', siteId, 'plugins'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>(`/sites/${siteId}/plugins`);
      return sitePluginsListResponseSchema.parse(data);
    },
    staleTime: 60_000,
    refetchInterval: opts.refetchInterval ?? false,
  });
}
