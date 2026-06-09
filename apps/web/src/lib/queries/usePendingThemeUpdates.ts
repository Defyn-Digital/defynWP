import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { pendingThemeUpdatesSchema } from '@/types/api';

/**
 * P2.8 — fetches the flat list of (site, theme) pairs with update_available=1
 * for the SPA's bulk update confirm dialog. Enabled-ONLY-on-dialog-open
 * (plan-bug trap #12) — set the dialogOpen flag from the parent component to
 * gate the fetch. NOT polling.
 *
 * Query key: ['pendingThemeUpdates'] so the bulk mutation can invalidate it.
 */
export function usePendingThemeUpdates(dialogOpen: boolean) {
  return useQuery({
    queryKey: ['pendingThemeUpdates'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>('/overview/pending-theme-updates');
      return pendingThemeUpdatesSchema.parse(data);
    },
    enabled: dialogOpen,
    staleTime: 30_000,
  });
}
