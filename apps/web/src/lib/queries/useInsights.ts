import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { insightsSchema } from '@/types/api';

export function useInsights() {
  return useQuery({
    queryKey: ['insights'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>('/insights');
      return insightsSchema.parse(data);
    },
    staleTime: 30_000,
  });
}
