import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { scanAllSecurityResponseSchema, type ScanAllSecurityResponse } from '@/types/api';

/**
 * P4.2 — POSTs /security/scan-all. Server refreshes the feed once + fan-outs a
 * SecurityScan job per owned site. On success invalidate ['security'] so the
 * fleet table reflects updated scan timestamps on the next refetch. No live poll
 * (the scan runs async via AS jobs).
 */
export function useScanAllSecurity() {
  const queryClient = useQueryClient();

  return useMutation<ScanAllSecurityResponse, Error, void>({
    mutationFn: async () => {
      const data = await apiClient.post<unknown>('/security/scan-all');
      return scanAllSecurityResponseSchema.parse(data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['security'] });
    },
  });
}
