import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useSiteReport } from '@/lib/queries/useSiteReport';
import { presetRange } from '@/lib/reportRange';
import type { RangePreset } from '@/lib/reportRange';
import { ReportHeader } from '@/components/report/ReportHeader';
import { ReportOverview } from '@/components/report/ReportOverview';
import { ReportUpdates } from '@/components/report/ReportUpdates';
import { ReportUptime } from '@/components/report/ReportUptime';
import { ReportSecurity } from '@/components/report/ReportSecurity';
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

  const { data, isLoading, isError } = useSiteReport(siteId, range.from, range.to);

  const setFrom = (from: string) => setRange((prev) => ({ ...prev, from }));
  const setTo = (to: string) => setRange((prev) => ({ ...prev, to }));

  return (
    <div className="report-print-root min-h-screen bg-zinc-50 p-8">
      <div className="mx-auto max-w-3xl space-y-6">
        {/* Controls — hidden on print via report-print.css */}
        <div className="report-controls flex flex-wrap items-end gap-3 rounded-md border bg-white p-4">
          <div className="flex flex-wrap gap-2">
            {PRESETS.map(({ preset, label }) => (
              <button
                key={preset}
                type="button"
                className="rounded-md border px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-50"
                onClick={() => setRange(presetRange(preset))}
              >
                {label}
              </button>
            ))}
          </div>

          <label className="flex flex-col text-xs text-zinc-500">
            From
            <input
              type="date"
              value={range.from}
              onChange={(e) => setFrom(e.target.value)}
              className="mt-1 rounded-md border px-2 py-1 text-sm text-zinc-800"
            />
          </label>
          <label className="flex flex-col text-xs text-zinc-500">
            To
            <input
              type="date"
              value={range.to}
              onChange={(e) => setTo(e.target.value)}
              className="mt-1 rounded-md border px-2 py-1 text-sm text-zinc-800"
            />
          </label>

          <button
            type="button"
            className="ml-auto rounded-md bg-zinc-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-zinc-800"
            onClick={() => window.print()}
          >
            Print / Save as PDF
          </button>

          <Link
            to={`/sites/${siteId}`}
            className="rounded-md border px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-50"
          >
            Back to site
          </Link>
        </div>

        {isLoading && <p className="text-sm text-zinc-500">Loading report…</p>}

        {isError && (
          <p className="text-sm text-red-600">Failed to load the report.</p>
        )}

        {!isLoading && !isError && data && (
          <div className="space-y-6">
            <ReportHeader site={data.site} period={data.period} />
            <ReportOverview overview={data.overview} />
            <ReportUpdates updates={data.updates} />
            <ReportUptime uptime={data.uptime} />
            <ReportSecurity security={data.security} />
          </div>
        )}
      </div>
    </div>
  );
}
