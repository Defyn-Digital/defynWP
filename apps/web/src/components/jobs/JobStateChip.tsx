import { Check, Clock, Loader2, Minus, X } from 'lucide-react';
import type { JobItemState, JobState } from '@/types/api';

type ChipState = JobState | JobItemState;

/**
 * P2.9 — SINGLE source of truth for state chip colors (guardrail #20).
 * Spec § 3.7 item palette; job-level states map onto the dominant item
 * state: in_progress → started (blue), completed → succeeded (green),
 * partial → failed (red).
 */
const STATE_CLASSES: Record<ChipState, string> = {
  queued: 'text-zinc-600 bg-zinc-100',
  started: 'text-blue-700 bg-blue-100',
  in_progress: 'text-blue-700 bg-blue-100',
  succeeded: 'text-green-700 bg-green-100',
  completed: 'text-green-700 bg-green-100',
  failed: 'text-red-700 bg-red-100',
  partial: 'text-red-700 bg-red-100',
  cancelled: 'text-zinc-500 bg-zinc-100 line-through',
};

const STATE_ICONS: Record<ChipState, typeof Check> = {
  queued: Clock,
  started: Loader2,
  in_progress: Loader2,
  succeeded: Check,
  completed: Check,
  failed: X,
  partial: X,
  cancelled: Minus,
};

interface JobStateChipProps {
  state: ChipState;
}

export function JobStateChip({ state }: JobStateChipProps) {
  const Icon = STATE_ICONS[state];
  const isSpinning = state === 'started' || state === 'in_progress';

  return (
    <span
      data-testid="job-state-chip"
      className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${STATE_CLASSES[state]}`}
    >
      <Icon className={`h-3 w-3${isSpinning ? ' animate-spin' : ''}`} aria-hidden="true" />
      {state.replace('_', ' ')}
    </span>
  );
}
