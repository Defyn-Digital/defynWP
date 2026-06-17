import { Check, Clock, Loader2, X } from 'lucide-react';
import type { Report } from '@/types/api';

type ReportStatus = Report['status'];

/**
 * P5.3 — SINGLE source of truth for report-status chip colors
 * (mirrors P2.9 JobStateChip): generating → amber + spinner, ready → blue,
 * sent → green, failed → red.
 */
const STATUS_CLASSES: Record<ReportStatus, string> = {
  generating: 'text-amber-700 bg-amber-100',
  ready: 'text-blue-700 bg-blue-100',
  sent: 'text-green-700 bg-green-100',
  failed: 'text-red-700 bg-red-100',
};

const STATUS_ICONS: Record<ReportStatus, typeof Check> = {
  generating: Loader2,
  ready: Clock,
  sent: Check,
  failed: X,
};

const STATUS_LABELS: Record<ReportStatus, string> = {
  generating: 'Generating',
  ready: 'Ready',
  sent: 'Sent',
  failed: 'Failed',
};

interface ReportStatusBadgeProps {
  status: ReportStatus;
  /**
   * P5.4 — when a report was delivered automatically (`sent_method === 'auto'`),
   * the otherwise-green "Sent" badge becomes a violet "Auto-sent" badge so
   * operators can tell scheduled sends apart from manual ones at a glance.
   */
  sentMethod?: string | null;
}

export function ReportStatusBadge({ status, sentMethod }: ReportStatusBadgeProps) {
  const isAuto = status === 'sent' && sentMethod === 'auto';
  const cls = isAuto ? 'text-violet-700 bg-violet-100' : STATUS_CLASSES[status];
  const label = isAuto ? 'Auto-sent' : STATUS_LABELS[status];
  const Icon = STATUS_ICONS[status];
  const isSpinning = status === 'generating';

  return (
    <span
      data-testid="report-status-badge"
      className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${cls}`}
    >
      <Icon className={`h-3 w-3${isSpinning ? ' animate-spin' : ''}`} aria-hidden="true" />
      {label}
    </span>
  );
}
