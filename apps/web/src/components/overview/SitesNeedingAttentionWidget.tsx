import { Link } from 'react-router-dom'
import type { Overview } from '@/types/api'
import { AttentionReasonChip } from '@/components/overview/AttentionReasonChip'

interface SitesNeedingAttentionWidgetProps {
  sites: Overview['sites_needing_attention']
}

export function SitesNeedingAttentionWidget({ sites }: SitesNeedingAttentionWidgetProps) {
  if (sites.length === 0) {
    return (
      <div className="rounded-md border bg-white p-4">
        <h3 className="mb-3 text-sm font-semibold">Sites needing attention</h3>
        <p className="text-sm text-muted-foreground">All sites healthy ✓</p>
      </div>
    )
  }

  return (
    <div className="rounded-md border bg-white p-4">
      <h3 className="mb-3 text-sm font-semibold">Sites needing attention ({sites.length})</h3>
      <ul className="divide-y divide-dashed">
        {sites.map((s) => (
          <li key={s.site_id} className="flex items-center gap-2 py-2 text-sm">
            <Link to={`/sites/${s.site_id}`} className="flex-1 font-medium hover:underline">
              {s.label}
            </Link>
            <div className="flex gap-1">
              {s.reasons.map((r) => (
                <AttentionReasonChip key={r} reason={r} />
              ))}
            </div>
          </li>
        ))}
      </ul>
    </div>
  )
}
