import { Link } from 'react-router-dom';
import { useMonitoring } from '@/lib/queries/useMonitoring';
import { MonitoringSummaryStrip } from '@/components/monitoring/MonitoringSummaryStrip';
import { MonitoringTable } from '@/components/monitoring/MonitoringTable';

export function Monitoring() {
  const { data, isLoading, isError } = useMonitoring();

  return (
    <div className="mx-auto max-w-5xl px-4 py-6">
      <div className="mb-5 flex items-baseline gap-3">
        <h1 className="text-xl font-semibold">Monitoring</h1>
        <Link to="/overview" className="text-sm text-zinc-600 underline-offset-4 hover:underline">← Overview</Link>
      </div>

      {isLoading && <p className="text-sm text-zinc-500">Loading…</p>}
      {isError && <p className="text-sm text-red-600">Couldn't load monitoring data.</p>}

      {data && (
        data.sites.length === 0 ? (
          <p className="text-sm text-zinc-500">No sites yet</p>
        ) : (
          <div className="space-y-5">
            <MonitoringSummaryStrip summary={data.summary} />
            <div className="rounded-lg border border-zinc-200 p-2">
              <MonitoringTable sites={data.sites} />
            </div>
          </div>
        )
      )}
    </div>
  );
}

export default Monitoring;
