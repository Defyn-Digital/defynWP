import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import {
  bulkUpdatePluginsResponseSchema,
  type BulkUpdatePluginsResponse,
} from '@/types/api';

export interface BulkUpdatePluginsRequest {
  updates: Array<{ site_id: number; slug: string }>;
}

/**
 * P2.7 — POSTs to /defyn/v1/overview/bulk-update-plugins. Server fan-outs
 * the existing P2.2 UpdateSitePlugin AS job per valid (site, slug) pair and
 * emits ONE fleet-scoped overview.bulk_plugin_update_requested activity event.
 *
 * On success: invalidate ['overview'] AND ['pendingPluginUpdates'] so the
 * Recent Activity widget refreshes + the next dialog open re-fetches the
 * (now-shrunk) pending list. Plan-bug trap #11: NOT ['sites'].
 */
export function useBulkUpdatePlugins() {
  const queryClient = useQueryClient();

  return useMutation<BulkUpdatePluginsResponse, Error, BulkUpdatePluginsRequest>({
    mutationFn: async ({ updates }) => {
      const data = await apiClient.post<unknown>('/overview/bulk-update-plugins', { updates });
      return bulkUpdatePluginsResponseSchema.parse(data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['overview'] });
      queryClient.invalidateQueries({ queryKey: ['pendingPluginUpdates'] });
    },
  });
}
