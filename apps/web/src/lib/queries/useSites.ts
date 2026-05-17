import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { sitesListSchema } from '@/types/api';

export function useSites() {
  return useQuery({
    queryKey: ['sites'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>('/sites');
      return sitesListSchema.parse(data);
    },
  });
}
