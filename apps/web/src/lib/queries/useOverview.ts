import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { overviewSchema } from '@/types/api';

export function useOverview() {
  return useQuery({
    queryKey: ['overview'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>('/overview');
      return overviewSchema.parse(data);
    },
    refetchInterval: 60_000,
    refetchIntervalInBackground: false,
  });
}
