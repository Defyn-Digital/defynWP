import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { activityListResponseSchema, type ActivityListResponse } from '@/types/api';

interface UseActivityParams {
  page?: number;
  perPage?: number;
  eventType?: string | null;
  siteId?: number | null;
}

export function useActivity(params: UseActivityParams = {}) {
  const { page = 1, perPage = 50, eventType = null, siteId = null } = params;
  return useQuery<ActivityListResponse>({
    queryKey: ['activity', page, perPage, eventType, siteId],
    queryFn: async () => {
      const qs = new URLSearchParams();
      qs.set('page', String(page));
      qs.set('per_page', String(perPage));
      if (eventType) qs.set('event_type', eventType);
      if (siteId !== null) qs.set('site_id', String(siteId));
      const data = await apiClient.get<unknown>(`/activity?${qs.toString()}`);
      return activityListResponseSchema.parse(data);
    },
  });
}
