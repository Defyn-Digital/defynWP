import { useSettings } from '@/lib/queries/useSettings';
import type { ReportBranding } from '@/types/api';

export function useReportBranding(): { data?: ReportBranding; isLoading: boolean } {
  const q = useSettings();
  return { data: q.data?.report_branding, isLoading: q.isLoading };
}
