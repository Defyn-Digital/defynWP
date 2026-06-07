import type { OverviewAttentionReason } from '@/types/api'

interface AttentionReasonChipProps {
  reason: OverviewAttentionReason
}

const PALETTE: Record<OverviewAttentionReason, { className: string; label: string }> = {
  offline:        { className: 'bg-red-100 text-red-800',     label: 'offline' },
  failed_update:  { className: 'bg-red-100 text-red-800',     label: 'failed update' },
  ssl_expiring:   { className: 'bg-amber-100 text-amber-800', label: 'ssl expiring' },
  sync_stale:     { className: 'bg-amber-100 text-amber-800', label: 'sync stale' },
}

export function AttentionReasonChip({ reason }: AttentionReasonChipProps) {
  const { className, label } = PALETTE[reason]
  return (
    <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${className}`}>
      {label}
    </span>
  )
}
