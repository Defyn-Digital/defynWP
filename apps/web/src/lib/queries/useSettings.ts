import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { settingsSchema } from '@/types/api';

export function useSettings() {
  return useQuery({
    queryKey: ['settings'],
    queryFn: async () => settingsSchema.parse(await apiClient.get<unknown>('/settings')),
  });
}
