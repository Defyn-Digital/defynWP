import { Link } from 'react-router-dom'
import { Activity, Shield, Gauge, BarChart3, FileText, ChevronRight } from 'lucide-react'

const SERVICES = [
  { icon: Activity, title: 'Uptime & incidents', desc: 'Availability monitoring across the fleet', to: '/monitoring' },
  { icon: Shield, title: 'Security', desc: 'Daily vulnerability scans', to: '/security' },
  { icon: Gauge, title: 'Performance', desc: 'Weekly PageSpeed scores', to: '/insights' },
  { icon: BarChart3, title: 'Analytics', desc: 'Traffic insights', to: '/insights' },
  { icon: FileText, title: 'Reports', desc: 'Client maintenance reports', to: '/reports' },
] as const

/** ManageWP-style Services column: quick links into each monitoring area. */
export function ServicesWidget({ openIncidents }: { openIncidents: number }) {
  return (
    <div className="rounded-xl border border-border bg-card">
      <div className="border-b border-border px-5 py-3">
        <h2 className="text-base font-medium">Services</h2>
      </div>
      <div className="divide-y divide-border">
        {SERVICES.map((s) => (
          <Link
            key={s.title}
            to={s.to}
            className="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-muted/50"
          >
            <s.icon className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden="true" />
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium">{s.title}</p>
              <p className="truncate text-xs text-muted-foreground">{s.desc}</p>
            </div>
            {s.title === 'Uptime & incidents' && openIncidents > 0 && (
              <span className="shrink-0 rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">
                {openIncidents} open
              </span>
            )}
            <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
          </Link>
        ))}
      </div>
    </div>
  )
}
