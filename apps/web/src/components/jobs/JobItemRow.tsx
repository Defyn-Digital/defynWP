import { Button } from '@/components/ui/button';
import { JobStateChip } from '@/components/jobs/JobStateChip';
import type { JobItem } from '@/types/api';

interface JobItemRowProps {
  item: JobItem;
  onRetry: (itemId: number) => void;
  retryPending: boolean;
}

/**
 * P2.9 — single item row inside JobItemsGroup (spec § 3.4). Per-row Retry
 * is ONE-CLICK with no confirmation (guardrail #21) and only renders when
 * the item state is `failed`.
 */
export function JobItemRow({ item, onRetry, retryPending }: JobItemRowProps) {
  return (
    <li className="flex items-center gap-2 py-1.5 text-sm text-zinc-700">
      <span className="flex-1 truncate">
        {item.resource_name}
        <span className="ml-2 font-mono text-xs text-zinc-500">
          {item.current_version ?? '?'} → {item.target_version ?? '?'}
        </span>
      </span>
      {item.state === 'failed' && item.error_message !== null && (
        <span className="max-w-[200px] truncate text-xs text-red-700" title={item.error_message}>
          {item.error_message}
        </span>
      )}
      <JobStateChip state={item.state} />
      {item.state === 'failed' && (
        <Button size="sm" variant="outline" disabled={retryPending} onClick={() => onRetry(item.id)}>
          Retry
        </Button>
      )}
    </li>
  );
}
