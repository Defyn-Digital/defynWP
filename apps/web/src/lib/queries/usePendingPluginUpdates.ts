import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { pendingPluginUpdatesSchema } from '@/types/api';

/**
 * P2.7 — fetches the flat list of (site, plugin) pairs with update_available=1
 * for the SPA's bulk update confirm dialog. Enabled-ONLY-on-dialog-open
 * (plan-bug trap #12) — set the dialogOpen flag from the parent component to
 * gate the fetch. NOT polling.
 *
 * Query key: ['pendingPluginUpdates'] so the bulk mutation can invalidate it.
 */
export function usePendingPluginUpdates(dialogOpen: boolean) {
  return useQuery({
    queryKey: ['pendingPluginUpdates'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>('/overview/pending-plugin-updates');
      return pendingPluginUpdatesSchema.parse(data);
    },
    enabled: dialogOpen,
  });
}
