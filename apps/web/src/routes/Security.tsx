import { useSecurity } from '@/lib/queries/useSecurity'
import { SecuritySummaryStrip } from '@/components/security/SecuritySummaryStrip'
import { SecurityFleetTable } from '@/components/security/SecurityFleetTable'
import { ScanAllSitesSecurityButton } from '@/components/security/ScanAllSitesSecurityButton'
import { PageHeader } from '@/components/layout/PageHeader'

export function Security() {
  const { data, isLoading, isError } = useSecurity()

  return (
    <div className="space-y-6 p-4 md:p-6">
      <PageHeader
        title="Security"
        subtitle="Wordfence vulnerability feed across your fleet"
        actions={data ? <ScanAllSitesSecurityButton totalSites={data.summary.total_sites} /> : undefined}
      />

      {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}
      {isError && <p className="text-sm text-red-600">Couldn't load security data.</p>}

      {data &&
        (data.summary.total_sites === 0 ? (
          <p className="text-sm text-muted-foreground">No sites yet.</p>
        ) : (
          <div className="space-y-5">
            <SecuritySummaryStrip summary={data.summary} />
            {data.summary.sites_at_risk === 0 && (
              <p className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                No known vulnerabilities across the fleet.
              </p>
            )}
            <div className="overflow-hidden rounded-xl border border-border bg-card">
              <SecurityFleetTable sites={data.sites} />
            </div>
          </div>
        ))}
    </div>
  )
}

export default Security
