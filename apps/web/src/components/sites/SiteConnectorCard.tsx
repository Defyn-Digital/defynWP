import { useState } from 'react'
import { RefreshCw, CheckCircle2, ArrowUpCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useConnectorLatestRelease } from '@/lib/queries/useConnectorLatestRelease'
import { useUpdateSiteConnector } from '@/lib/mutations/useUpdateSiteConnector'
import type { Site } from '@/types/api'

/** Numeric-dotted version compare: >0 if a>b, <0 if a<b, 0 if equal. */
function cmpVersion(a: string, b: string): number {
  const pa = a.split('.').map((n) => parseInt(n, 10) || 0)
  const pb = b.split('.').map((n) => parseInt(n, 10) || 0)
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const d = (pa[i] ?? 0) - (pb[i] ?? 0)
    if (d !== 0) return d > 0 ? 1 : -1
  }
  return 0
}

interface Props {
  site: Site
}

/**
 * Connector version + self-update control. Shows the installed connector
 * version, the latest published release, and an "Update connector" button when
 * the site is behind. Hidden entirely if the latest release can't be resolved
 * (e.g. GitHub unreachable) — nothing to offer.
 */
export function SiteConnectorCard({ site }: Props) {
  const { data: latest } = useConnectorLatestRelease()
  const { mutate, isPending } = useUpdateSiteConnector(site.id)
  const [requested, setRequested] = useState(false)

  const current = site.connector_version ?? null
  const behind = latest != null && (current === null || cmpVersion(latest.version, current) > 0)

  return (
    <div className="rounded-xl border border-border bg-card p-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <h2 className="text-sm font-semibold">Connector</h2>
            {behind ? (
              <ArrowUpCircle className="h-4 w-4 text-amber-500" aria-hidden="true" />
            ) : current ? (
              <CheckCircle2 className="h-4 w-4 text-emerald-500" aria-hidden="true" />
            ) : null}
          </div>
          <p className="mt-1 text-sm text-muted-foreground">
            {current ? `Installed v${current}` : 'Version unknown — sync to detect'}
            {latest ? ` · latest v${latest.version}` : ''}
            {requested ? ' · update queued, refreshes after next sync' : ''}
          </p>
        </div>

        {behind && (
          <Button size="sm" disabled={isPending} onClick={() => mutate(undefined, { onSuccess: () => setRequested(true) })}>
            {isPending ? (
              <>
                <RefreshCw className="mr-1.5 h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                Updating…
              </>
            ) : (
              <>Update connector{latest ? ` → v${latest.version}` : ''}</>
            )}
          </Button>
        )}
      </div>
    </div>
  )
}
