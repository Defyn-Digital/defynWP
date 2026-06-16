import { apiClient } from '@/lib/apiClient';

/** Fetch the branded PDF (auth'd) and trigger a browser download. */
export async function downloadReportPdf(siteId: number, from: string, to: string): Promise<void> {
  const blob = await apiClient.getBlob(`/sites/${siteId}/report.pdf?from=${from}&to=${to}`);
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `maintenance-report-${siteId}-${from}-to-${to}.pdf`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}
