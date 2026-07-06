import { apiClient } from '@/lib/apiClient';

interface CreatedReport { data: { report: { id: number; status: string } } }
interface ReportsList { data: { reports: Array<{ id: number; status: string }> } }

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

/**
 * Generate + download the branded maintenance PDF.
 *
 * The PDF is rendered by the async report-queue job (which runs with proper
 * memory/time limits) rather than synchronously in the request — the per-request
 * dompdf render OOMs the web worker on hosts with a tight REST memory cap (Kinsta),
 * which is why the old direct GET /report.pdf failed. We enqueue a report for the
 * range, poll until it's ready (generation is kicked immediately server-side, so
 * this is ~1-3s), then download the stored file.
 */
export async function downloadReportPdf(siteId: number, from: string, to: string): Promise<void> {
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
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `maintenance-report-${siteId}-${from}-to-${to}.pdf`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}
