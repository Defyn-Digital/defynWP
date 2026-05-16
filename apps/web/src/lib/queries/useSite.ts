import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { siteSchema, type Site } from '@/types/api';

interface UseSiteOptions {
  /** Poll every N ms while site.status === 'pending'. Set to 0 to disable. */
  pollWhilePending?: number;
}

export function useSite(id: number, opts: UseSiteOptions = {}) {
  const pollInterval = opts.pollWhilePending ?? 0;
  return useQuery({
    queryKey: ['sites', id],
    queryFn: async () => {
      const data = await apiClient.get<unknown>(`/sites/${id}`);
      return siteSchema.parse(data);
    },
    refetchInterval: (query) => {
      const data = query.state.data as Site | undefined;
      if (data?.status === 'pending' && pollInterval > 0) {
        return pollInterval;
      }
      return false;
    },
  });
}
