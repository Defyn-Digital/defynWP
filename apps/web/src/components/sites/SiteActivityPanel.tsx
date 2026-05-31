import type { Site } from '@/types/api';
import { useSiteActivity } from '@/lib/queries/useSiteActivity';
import { ActivityRow } from '@/components/activity/ActivityRow';

interface SiteActivityPanelProps {
  site: Site;
}

export function SiteActivityPanel({ site }: SiteActivityPanelProps) {
  const { data, isLoading } = useSiteActivity(site.id);

  return (
    <section className="mt-8">
      <h2 className="text-lg font-semibold mb-3">Recent activity</h2>
      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : data && data.events.length > 0 ? (
        <div className="border-t">
          {data.events.map((event) => (
            <ActivityRow key={event.id} event={event} />
          ))}
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">
          No activity yet — events will appear after the first sync or ping.
        </p>
      )}
    </section>
  );
}
