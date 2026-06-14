import { Link } from 'react-router-dom'
import type { Overview } from '@/types/api'

interface PendingUpdatesWidgetProps {
  counts: Overview['pending_updates']
}

interface CountCardProps {
  to: string
  label: string
  num: number | string
  sub: string
}

function CountCard({ to, label, num, sub }: CountCardProps) {
  return (
    <Link
      to={to}
      className="block rounded-md border bg-white p-4 transition hover:border-foreground"
    >
      <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="mt-1 text-3xl font-bold text-foreground">{num}</p>
      <p className="mt-0.5 text-xs text-muted-foreground">{sub}</p>
    </Link>
  )
}

export function PendingUpdatesWidget({ counts }: PendingUpdatesWidgetProps) {
  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
      <CountCard
        to="/overview/plugins"
        label="Plugin updates"
        num={counts.plugins}
        sub={`across ${counts.sites_with_any_update} site${counts.sites_with_any_update === 1 ? '' : 's'}`}
      />
      <CountCard
        to="/overview/themes"
        label="Theme updates"
        num={counts.themes}
        sub="across all sites"
      />
      <CountCard
        to="/sites?filter=has-core-update"
        label="WP core updates"
        num={`${counts.cores_minor} / ${counts.cores_major}`}
        sub="minor / major"
      />
    </div>
  )
}
