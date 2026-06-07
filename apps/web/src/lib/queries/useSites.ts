import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { sitesListSchema } from '@/types/api';

type SitesFilter = 'has-plugin-updates' | 'has-theme-updates' | 'has-core-update';

interface UseSitesOptions {
  filter?: SitesFilter;
}

export function useSites(opts: UseSitesOptions = {}) {
  return useQuery({
    queryKey: ['sites', opts.filter ?? null],
    queryFn: async () => {
      const path = opts.filter ? `/sites?filter=${opts.filter}` : '/sites';
      const data = await apiClient.get<unknown>(path);
      return sitesListSchema.parse(data);
    },
  });
}
