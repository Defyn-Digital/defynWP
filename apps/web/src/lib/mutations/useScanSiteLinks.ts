import { useEffect, useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { useSiteBrokenLinks } from '@/lib/queries/useSiteBrokenLinks';

export function useScanSiteLinks(siteId: number) {
  const queryClient = useQueryClient();
  const preScanRef = useRef<string | null>(null);
  const [isPolling, setIsPolling] = useState(false);

  const query = useSiteBrokenLinks(siteId, { refetchInterval: isPolling ? 2_000 : false });

  // Stop polling once last_link_scan_at changes from the pre-scan value.
  // Dep array uses the primitive string — never an object/array ref — to avoid infinite render loops.
  useEffect(() => {
    if (!isPolling) return;
    const latest = query.data?.last_link_scan_at ?? null;
    if (latest !== preScanRef.current) {
      setIsPolling(false);
    }
  }, [query.data?.last_link_scan_at, isPolling]);

  // Hard timeout at 60s to prevent polling forever if the scan never completes.
  useEffect(() => {
    if (!isPolling) return;
    const timeoutId = window.setTimeout(() => setIsPolling(false), 60_000);
    return () => window.clearTimeout(timeoutId);
  }, [isPolling]);

  const mutation = useMutation({
    mutationFn: async () => {
      // Capture the current last_link_scan_at BEFORE the POST so we can detect when it changes.
      preScanRef.current = query.data?.last_link_scan_at ?? null;
      return apiClient.post<{ scheduled: boolean }>(
        `/sites/${siteId}/links/scan`,
      );
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['siteBrokenLinks', siteId] });
      setIsPolling(true);
    },
  });

  return {
    scan: () => mutation.mutate(),
    isPending: mutation.isPending,
    isPolling,
    error: mutation.error,
  };
}
