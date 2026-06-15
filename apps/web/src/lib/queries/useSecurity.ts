import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { securitySchema } from '@/types/api';

export function useSecurity() {
  return useQuery({
    queryKey: ['security'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>('/security');
      return securitySchema.parse(data);
    },
    staleTime: 30_000,
  });
}
