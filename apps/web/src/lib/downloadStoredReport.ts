import { apiClient } from '@/lib/apiClient';

/** Fetch a stored (already-generated) report PDF (auth'd) and trigger a browser download. */
export async function downloadStoredReport(
  siteId: number,
  reportId: number,
  filename: string,
): Promise<void> {
  const blob = await apiClient.getBlob(`/sites/${siteId}/reports/${reportId}/download`);
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}
