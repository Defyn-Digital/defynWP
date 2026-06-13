import { useState } from 'react';
import { JobItemRow } from '@/components/jobs/JobItemRow';
import type { JobItem } from '@/types/api';

interface JobItemsGroupProps {
  siteLabel: string;
  items: JobItem[];
  defaultExpanded: boolean;
  onRetryItem: (itemId: number) => void;
  retryPending: boolean;
}

/**
 * P2.9 — per-site collapsible group on the detail view (spec § 3.4).
 * JobDetail passes defaultExpanded={index < 3} so long lists collapse
 * with the first 3 sites expanded (mirrors the P2.7 dialog pattern).
 */
export function JobItemsGroup({ siteLabel, items, defaultExpanded, onRetryItem, retryPending }: JobItemsGroupProps) {
  const [expanded, setExpanded] = useState(defaultExpanded);

  return (
    <div data-testid="job-items-group" className="rounded border border-zinc-200 p-3">
      <button
        type="button"
        className="flex w-full items-center justify-between text-sm font-semibold text-zinc-900"
        onClick={() => setExpanded((prev) => !prev)}
        aria-expanded={expanded}
      >
        <span>
          {siteLabel} ({items.length} item{items.length === 1 ? '' : 's'})
        </span>
        <span aria-hidden="true">{expanded ? '▾' : '▸'}</span>
      </button>
      {expanded && (
        <ul className="mt-2 divide-y divide-zinc-100">
          {items.map((item) => (
            <JobItemRow key={item.id} item={item} onRetry={onRetryItem} retryPending={retryPending} />
          ))}
        </ul>
      )}
    </div>
  );
}
