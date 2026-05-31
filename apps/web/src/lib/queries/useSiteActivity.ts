import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { activityListResponseSchema, type ActivityListResponse } from '@/types/api';

export function useSiteActivity(siteId: number) {
  return useQuery<ActivityListResponse>({
    queryKey: ['site', siteId, 'activity'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>(`/sites/${siteId}/activity?per_page=10`);
      return activityListResponseSchema.parse(data);
    },
  });
}
