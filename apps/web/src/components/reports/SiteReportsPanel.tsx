import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useSiteReports } from '@/lib/queries/useSiteReports';
import { useGenerateReport } from '@/lib/mutations/useGenerateReport';
import { useSendReport } from '@/lib/mutations/useSendReport';
import { useDeleteReport } from '@/lib/mutations/useDeleteReport';
import { useSetClientEmail } from '@/lib/mutations/useSetClientEmail';
import { downloadStoredReport } from '@/lib/downloadStoredReport';
import type { Report } from '@/types/api';
import { ReportStatusBadge } from './ReportStatusBadge';
import { GenerateReportDialog } from './GenerateReportDialog';
import { SendReportDialog } from './SendReportDialog';
import { DeleteReportDialog } from './DeleteReportDialog';

interface SiteReportsPanelProps {
  siteId: number;
  clientEmail: string | null;
}

// --- helpers ---

/** Human-readable byte size; `—` when null (not yet generated). */
function formatSize(bytes: number | null): string {
  if (bytes === null) return '—';
  if (bytes < 1024) return `${bytes} B`;
  const kb = bytes / 1024;
  if (kb < 1024) return `${kb.toFixed(1)} KB`;
  return `${(kb / 1024).toFixed(1)} MB`;
}

/** A report can be downloaded/sent only once its PDF exists. */
function isSendable(status: Report['status']): boolean {
  return status === 'ready' || status === 'sent';
}

// --- main component ---

export function SiteReportsPanel({ siteId, clientEmail }: SiteReportsPanelProps) {
  const { data, isLoading, isError } = useSiteReports(siteId);
  const generate = useGenerateReport(siteId);
  const send = useSendReport(siteId);
  const remove = useDeleteReport(siteId);
  const setClientEmail = useSetClientEmail(siteId);

  // Dialog open-state is tracked by PRIMITIVE report ids (number | null), never
  // by a captured report object — keeps the render path free of object-keyed
  // effects (P2.10 render-loop guardrail).
  const [generateOpen, setGenerateOpen] = useState(false);
  const [sendForId, setSendForId] = useState<number | null>(null);
  const [deleteForId, setDeleteForId] = useState<number | null>(null);

  // Client-email field seeded ONCE from the primitive `clientEmail` prop via
  // lazy useState — no useEffect on an object/array.
  const [emailDraft, setEmailDraft] = useState(() => clientEmail ?? '');

  const reports = data?.reports ?? [];

  const handleDownload = (report: Report) => {
    downloadStoredReport(siteId, report.id, `report-${report.id}.pdf`);
  };

  return (
    <section className="space-y-3 border-t pt-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-lg font-semibold">Reports</h3>
        <Button variant="outline" size="sm" onClick={() => setGenerateOpen(true)}>
          Generate report
        </Button>
      </header>

      <GenerateReportDialog
        open={generateOpen}
        onCancel={() => setGenerateOpen(false)}
        onConfirm={(range) => {
          generate.mutate(range);
          setGenerateOpen(false);
        }}
      />

      {/* Inline client-email setting */}
      <div className="flex flex-wrap items-end gap-2">
        <label className="flex flex-col text-xs text-zinc-500">
          Client email
          <Input
            type="email"
            value={emailDraft}
            onChange={(e) => setEmailDraft(e.target.value)}
            placeholder="client@example.com"
            aria-label="Client email"
            className="mt-1 w-64"
          />
        </label>
        <Button
          variant="outline"
          size="sm"
          onClick={() => setClientEmail.mutate(emailDraft.trim())}
          disabled={setClientEmail.isPending}
        >
          Save
        </Button>
      </div>

      {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}

      {isError && (
        <p className="text-sm text-red-600">Failed to load reports.</p>
      )}

      {!isLoading && !isError && reports.length === 0 && (
        <p className="text-sm text-zinc-600">No reports yet</p>
      )}

      {!isLoading && !isError && reports.length > 0 && (
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b text-left text-xs uppercase tracking-wide text-zinc-500">
              <th className="py-2 font-medium">Date</th>
              <th className="py-2 font-medium">Title</th>
              <th className="py-2 font-medium">Size</th>
              <th className="py-2 font-medium">Status</th>
              <th className="py-2 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {reports.map((r) => {
              const sendable = isSendable(r.status);
              return (
                <tr key={r.id} className="border-b last:border-b-0 align-top">
                  <td className="py-2 text-zinc-600">{r.created_at}</td>
                  <td className="py-2 text-zinc-800">{r.title}</td>
                  <td className="py-2 text-zinc-600">{formatSize(r.file_size)}</td>
                  <td className="py-2">
                    <ReportStatusBadge status={r.status} />
                  </td>
                  <td className="py-2">
                    <div className="flex items-center justify-end gap-2">
                      <button
                        type="button"
                        className="text-blue-600 hover:text-blue-700 disabled:cursor-not-allowed disabled:text-zinc-300"
                        onClick={() => handleDownload(r)}
                        disabled={!sendable}
                      >
                        Download
                      </button>
                      <button
                        type="button"
                        className="text-blue-600 hover:text-blue-700 disabled:cursor-not-allowed disabled:text-zinc-300"
                        onClick={() => setSendForId(r.id)}
                        disabled={!sendable}
                      >
                        Send
                      </button>
                      <button
                        type="button"
                        className="text-zinc-500 hover:text-zinc-700"
                        onClick={() => setDeleteForId(r.id)}
                      >
                        Delete
                      </button>
                    </div>

                    <SendReportDialog
                      open={sendForId === r.id}
                      clientEmail={clientEmail}
                      onCancel={() => setSendForId(null)}
                      onConfirm={({ recipientEmail, note }) => {
                        send.mutate({ reportId: r.id, recipientEmail, note });
                        setSendForId(null);
                      }}
                    />

                    <DeleteReportDialog
                      open={deleteForId === r.id}
                      onCancel={() => setDeleteForId(null)}
                      onConfirm={() => {
                        remove.mutate(r.id);
                        setDeleteForId(null);
                      }}
                    />
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      )}
    </section>
  );
}
