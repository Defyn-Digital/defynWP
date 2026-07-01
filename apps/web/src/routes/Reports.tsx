import { Link } from 'react-router-dom'
import { MailCheck, MailX, CalendarClock } from 'lucide-react'
import { Skeleton } from '@/components/ui/skeleton'
import { useSites } from '@/lib/queries/useSites'
import { PageHeader } from '@/components/layout/PageHeader'
import { KpiCard } from '@/components/overview/KpiCard'

export default function Reports() {
  const { data, isLoading, isError } = useSites()
  const sites = data?.sites ?? []

  const autoSendOn = sites.filter((s) => s.auto_send_reports).length
  const withEmail = sites.filter((s) => s.client_email).length
  const missingEmail = sites.filter((s) => !s.client_email).length

  return (
    <div className="space-y-6 p-4 md:p-6">
      <PageHeader
        title="Reports"
        subtitle="Branded maintenance reports for your clients — view, download a PDF, or email it."
      />

      {isLoading && (
        <div className="space-y-2">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-16 w-full" />
          ))}
        </div>
      )}

      {isError && (
        <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
          Could not load your sites.
        </div>
      )}

      {!isLoading && !isError && sites.length === 0 && (
        <div className="rounded-xl border border-border bg-card p-6 text-sm text-muted-foreground">
          No sites yet. Connect a site first — its report will then be available here.
        </div>
      )}

      {sites.length > 0 && (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <KpiCard label="Auto-send on" value={autoSendOn} icon={CalendarClock} />
            <KpiCard label="Client email set" value={withEmail} icon={MailCheck} />
            <KpiCard label="No client email" value={missingEmail} icon={MailX} tone={missingEmail > 0 ? 'warning' : 'default'} />
          </div>

          <div className="overflow-hidden rounded-xl border border-border bg-card">
            <div className="border-b border-border px-4 py-3 text-sm font-medium">Per-site reporting</div>
            {sites.map((site, i) => (
              <div
                key={site.id}
                className={`flex items-center gap-3 px-4 py-3 ${i > 0 ? 'border-t border-border' : ''}`}
              >
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{site.label || site.url}</p>
                  <p className={`truncate text-xs ${site.client_email ? 'text-muted-foreground' : 'text-amber-700'}`}>
                    {site.client_email || 'No client email set'}
                  </p>
                </div>
                <span
                  className={`hidden shrink-0 rounded-full px-2.5 py-0.5 text-xs sm:inline-block ${
                    site.auto_send_reports
                      ? 'bg-emerald-100 text-emerald-700'
                      : 'bg-muted text-muted-foreground'
                  }`}
                >
                  {site.auto_send_reports ? 'Monthly auto' : 'Manual'}
                </span>
                <Link
                  to={`/sites/${site.id}/report`}
                  className="shrink-0 text-sm text-primary hover:underline"
                >
                  View report
                </Link>
              </div>
            ))}
          </div>
        </>
      )}
    </div>
  )
}
