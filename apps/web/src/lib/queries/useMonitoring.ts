import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { monitoringSchema } from '@/types/api';

export function useMonitoring() {
  return useQuery({
    queryKey: ['monitoring'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>('/monitoring');
      return monitoringSchema.parse(data);
    },
    refetchInterval: 30_000,
    refetchIntervalInBackground: false,
  });
}
