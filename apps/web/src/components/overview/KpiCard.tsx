import { Link } from 'react-router-dom'
import type { LucideIcon } from 'lucide-react'

interface KpiCardProps {
  label: string
  value: string | number
  icon: LucideIcon
  to?: string
  tone?: 'default' | 'warning' | 'success'
}

const toneText: Record<NonNullable<KpiCardProps['tone']>, string> = {
  default: 'text-foreground',
  warning: 'text-amber-600',
  success: 'text-emerald-600',
}

export function KpiCard({ label, value, icon: Icon, to, tone = 'default' }: KpiCardProps) {
  const body = (
    <div className="flex flex-col gap-2 rounded-xl border border-border bg-card p-4 transition-colors hover:border-border/80">
      <div className="flex items-center justify-between">
        <span className="text-sm text-muted-foreground">{label}</span>
        <Icon className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
      </div>
      <span className={`text-2xl font-semibold ${toneText[tone]}`}>{value}</span>
    </div>
  )
  return to ? (
    <Link to={to} className="block focus:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-xl">
      {body}
    </Link>
  ) : (
    body
  )
}
