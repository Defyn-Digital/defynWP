import { Link } from 'react-router-dom';
import { useSecurity } from '@/lib/queries/useSecurity';
import { SecuritySummaryStrip } from '@/components/security/SecuritySummaryStrip';
import { SecurityFleetTable } from '@/components/security/SecurityFleetTable';
import { ScanAllSitesSecurityButton } from '@/components/security/ScanAllSitesSecurityButton';

export function Security() {
  const { data, isLoading, isError } = useSecurity();

  return (
    <div className="mx-auto max-w-5xl px-4 py-6">
      <div className="mb-5 flex items-baseline justify-between">
        <div className="flex items-baseline gap-3">
          <h1 className="text-xl font-semibold">Security</h1>
          <Link to="/overview" className="text-sm text-zinc-600 underline-offset-4 hover:underline">← Overview</Link>
        </div>
        {data && (
          <ScanAllSitesSecurityButton totalSites={data.summary.total_sites} />
        )}
      </div>

      {isLoading && <p className="text-sm text-zinc-500">Loading…</p>}
      {isError && <p className="text-sm text-red-600">Couldn't load security data.</p>}

      {data && (
        data.summary.total_sites === 0 ? (
          <p className="text-sm text-zinc-500">No sites yet</p>
        ) : (
          <div className="space-y-5">
            <SecuritySummaryStrip summary={data.summary} />
            {data.summary.sites_at_risk === 0 && (
              <p className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                No known vulnerabilities across the fleet
              </p>
            )}
            <div className="rounded-lg border border-zinc-200 p-2">
              <SecurityFleetTable sites={data.sites} />
            </div>
          </div>
        )
      )}
    </div>
  );
}

export default Security;
