import { Link } from 'react-router-dom'
import type { Overview } from '@/types/api'

interface RecentActivityWidgetProps {
  events: Overview['recent_activity']
}

const MAX_ROWS = 25

export function RecentActivityWidget({ events }: RecentActivityWidgetProps) {
  const rows = events.slice(0, MAX_ROWS)

  return (
    <div className="rounded-md border bg-white p-4">
      <h3 className="mb-3 text-sm font-semibold">Recent activity</h3>
      {rows.length === 0 ? (
        <p className="text-sm text-muted-foreground">No recent events</p>
      ) : (
        <ul className="divide-y divide-dashed">
          {rows.map((e) => (
            <li
              key={e.id}
              data-testid="activity-row"
              className="flex items-center gap-2 py-1.5 text-xs"
            >
              <span className="flex-1 font-mono text-muted-foreground">{e.event_type}</span>
              {e.site_id !== null ? (
                <Link to={`/sites/${e.site_id}`} className="text-foreground hover:underline">
                  {e.site_label ?? `site ${e.site_id}`}
                </Link>
              ) : (
                <span className="text-muted-foreground">—</span>
              )}
              <span className="text-muted-foreground">{e.created_at.slice(-8)}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
