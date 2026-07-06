import { apiClient } from '@/lib/apiClient';

interface CreatedReport { data: { report: { id: number; status: string } } }
interface ReportRow { id: number; status: string; range_from: string; range_to: string }
interface ReportsList { data: { reports: ReportRow[] } }

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

function triggerDownload(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

/**
 * Generate + download the branded maintenance PDF via the async report-queue.
 *
 * The render runs in a background job (proper memory/time limits) — the old
 * per-request dompdf render OOMed on Kinsta. To avoid creating a duplicate on
 * every click (and exhausting the 10/hour report-generate rate limit → 429), we
 * first reuse a recently-ready report for the SAME date range if one exists, and
 * only enqueue a new render when there isn't one.
 */
export async function downloadReportPdf(siteId: number, from: string, to: string): Promise<void> {
  const filename = `maintenance-report-${siteId}-${from}-to-${to}.pdf`;

  // 1) Reuse an existing ready report for this exact range (no new generate).
  try {
    const existing = await apiClient.get<ReportsList>(`/sites/${siteId}/reports`);
    const match = existing.data.reports.find(
      (r) => r.status === 'ready' && r.range_from === from && r.range_to === to,
    );
    if (match) {
      const blob = await apiClient.getBlob(`/sites/${siteId}/reports/${match.id}/download`);
      triggerDownload(blob, filename);
      return;
    }
  } catch {
    // fall through to generate
  }

  // 2) None exists — enqueue a render, poll until ready, then download.
  const created = await apiClient.post<CreatedReport>(`/sites/${siteId}/reports?from=${from}&to=${to}`);
  const reportId = created.data.report.id;

  let status = created.data.report.status;
  for (let i = 0; i < 20 && status === 'generating'; i++) {
    await sleep(1500);
    const list = await apiClient.get<ReportsList>(`/sites/${siteId}/reports`);
    status = list.data.reports.find((r) => r.id === reportId)?.status ?? status;
  }
  if (status !== 'ready') {
    throw new Error(status === 'failed' ? 'Report generation failed.' : 'The report is taking too long to generate.');
  }

  const blob = await apiClient.getBlob(`/sites/${siteId}/reports/${reportId}/download`);
  triggerDownload(blob, filename);
}
