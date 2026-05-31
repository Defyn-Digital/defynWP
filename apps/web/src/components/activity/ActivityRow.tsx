import type { ActivityEvent } from '@/types/api';

interface ActivityRowProps {
  event: ActivityEvent;
}

export function ActivityRow({ event }: ActivityRowProps) {
  return (
    <div data-testid="activity-row" className="flex items-start gap-3 py-3 border-b">
      <div className="text-xs text-muted-foreground w-44 shrink-0">{event.created_at}</div>
      <div className="font-mono text-sm w-48 shrink-0">{event.event_type}</div>
      <div className="flex-1 text-sm text-muted-foreground break-all">
        {event.details ? JSON.stringify(event.details) : null}
      </div>
    </div>
  );
}
