import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { presetRange } from '@/lib/reportRange';
import type { RangePreset } from '@/lib/reportRange';

interface GenerateReportDialogProps {
  open: boolean;
  onCancel: () => void;
  onConfirm: (range: { from: string; to: string }) => void;
}

interface PresetButton {
  preset: RangePreset;
  label: string;
}

const PRESETS: PresetButton[] = [
  { preset: 'thisMonth', label: 'This month' },
  { preset: 'lastMonth', label: 'Last month' },
  { preset: 'last30', label: 'Last 30 days' },
];

/**
 * P5.3 — date-range picker for queuing a new report.
 *
 * `range` is seeded via lazy `useState(() => presetRange('last30'))` — a
 * primitive-valued object built ONCE, never via a useEffect keyed on a fresh
 * array/object ref (P2.10 render-loop guardrail). Neutral primary (read-side).
 */
export function GenerateReportDialog({ open, onCancel, onConfirm }: GenerateReportDialogProps) {
  const cancelRef = useRef<HTMLButtonElement>(null);
  const [range, setRange] = useState(() => presetRange('last30'));

  useEffect(() => {
    if (open) {
      cancelRef.current?.focus();
    }
  }, [open]);

  if (!open) {
    return null;
  }

  const titleId = 'generate-report-confirm-title';
  const setFrom = (from: string) => setRange((prev) => ({ ...prev, from }));
  const setTo = (to: string) => setRange((prev) => ({ ...prev, to }));

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="mt-3 rounded-md border border-zinc-200 bg-white p-4 shadow-sm"
    >
      <h3 id={titleId} className="text-sm font-semibold text-zinc-900">
        Generate report
      </h3>

      <div className="mt-3 flex flex-wrap gap-2">
        {PRESETS.map(({ preset, label }) => (
          <button
            key={preset}
            type="button"
            className="rounded-md border px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-50"
            onClick={() => setRange(presetRange(preset))}
          >
            {label}
          </button>
        ))}
      </div>

      <div className="mt-3 flex flex-wrap gap-3">
        <label className="flex flex-col text-xs text-zinc-500">
          From
          <Input
            type="date"
            value={range.from}
            onChange={(e) => setFrom(e.target.value)}
            className="mt-1 w-40"
          />
        </label>
        <label className="flex flex-col text-xs text-zinc-500">
          To
          <Input
            type="date"
            value={range.to}
            onChange={(e) => setTo(e.target.value)}
            className="mt-1 w-40"
          />
        </label>
      </div>

      <div className="mt-4 flex items-center justify-end gap-2">
        <Button ref={cancelRef} variant="outline" onClick={onCancel}>
          Cancel
        </Button>
        <Button variant="default" onClick={() => onConfirm(range)}>
          Generate
        </Button>
      </div>
    </div>
  );
}
