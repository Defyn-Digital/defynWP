import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useSiteReport } from '@/lib/queries/useSiteReport';
import { presetRange } from '@/lib/reportRange';
import type { RangePreset } from '@/lib/reportRange';
import { ReportHeader } from '@/components/report/ReportHeader';
import { ReportOverview } from '@/components/report/ReportOverview';
import { ReportPerformance } from '@/components/report/ReportPerformance';
import { ReportAnalytics } from '@/components/report/ReportAnalytics';
import { ReportUpdates } from '@/components/report/ReportUpdates';
import { ReportUptime } from '@/components/report/ReportUptime';
import { ReportSecurity } from '@/components/report/ReportSecurity';
import { downloadReportPdf } from '@/lib/downloadReportPdf';
import { Button } from '@/components/ui/button';
import '@/components/report/report-print.css';

interface PresetButton {
  preset: RangePreset;
  label: string;
}

const PRESETS: PresetButton[] = [
  { preset: 'thisMonth', label: 'This month' },
  { preset: 'lastMonth', label: 'Last month' },
  { preset: 'last30', label: 'Last 30 days' },
];

export default function SiteReport() {
  const { id } = useParams<{ id: string }>();
  const siteId = Number(id);

  // Default to a trailing-30-day window. `range` is the only mutable state —
  // the report itself is derived purely from the query data (no useEffect on
  // fresh arrays, so no render loop).
  const [range, setRange] = useState(() => presetRange('last30'));
  const [downloadError, setDownloadError] = useState(false);

  const { data, isLoading, isError } = useSiteReport(siteId, range.from, range.to);

  const setFrom = (from: string) => setRange((prev) => ({ ...prev, from }));
  const setTo = (to: string) => setRange((prev) => ({ ...prev, to }));

  return (
    <div className="report-print-root min-h-screen bg-muted/40 p-4 md:p-8">
      <div className="mx-auto max-w-3xl space-y-6">
        {/* Controls — hidden on print via report-print.css */}
        <div className="report-controls flex flex-wrap items-end gap-3 rounded-lg border border-border bg-card p-4">
          <div className="flex flex-wrap gap-2">
            {PRESETS.map(({ preset, label }) => (
              <Button
                key={preset}
                variant="outline"
                size="sm"
                onClick={() => setRange(presetRange(preset))}
              >
                {label}
              </Button>
            ))}
          </div>

          <label className="flex flex-col text-xs text-muted-foreground">
            From
            <input
              type="date"
              value={range.from}
              onChange={(e) => setFrom(e.target.value)}
              className="mt-1 rounded-md border border-input bg-background px-2 py-1 text-sm"
            />
          </label>
          <label className="flex flex-col text-xs text-muted-foreground">
            To
            <input
              type="date"
              value={range.to}
              onChange={(e) => setTo(e.target.value)}
              className="mt-1 rounded-md border border-input bg-background px-2 py-1 text-sm"
            />
          </label>

          <Button
            variant="outline"
            size="sm"
            className="ml-auto"
            onClick={() => {
              setDownloadError(false);
              downloadReportPdf(siteId, range.from, range.to).catch(() => setDownloadError(true));
            }}
          >
            Download PDF
          </Button>

          <Button size="sm" onClick={() => window.print()}>
            Print / Save as PDF
          </Button>

          <Button variant="outline" size="sm" asChild>
            <Link to={`/sites/${siteId}`}>Back to site</Link>
          </Button>
        </div>

        {downloadError && (
          <p className="report-controls rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive">
            Couldn&apos;t generate the PDF.
          </p>
        )}

        {isLoading && <p className="text-sm text-muted-foreground">Loading report…</p>}

        {isError && (
          <p className="rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive">
            Failed to load the report.
          </p>
        )}

        {!isLoading && !isError && data && (
          <div className="space-y-6 rounded-lg border border-border bg-card p-6 md:p-8">
            <ReportHeader site={data.site} period={data.period} />
            <ReportOverview overview={data.overview} />
            <ReportPerformance performance={data.performance} />
            <ReportAnalytics analytics={data.analytics} />
            <ReportUpdates updates={data.updates} />
            <ReportUptime uptime={data.uptime} />
            <ReportSecurity security={data.security} />
          </div>
        )}
      </div>
    </div>
  );
}
