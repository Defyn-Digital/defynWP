import { Link } from 'react-router-dom';

/**
 * P3.2 — nav link to /monitoring (no badge — monitoring has no active-count concept).
 * Rendered in the Overview header beside <JobsNavLink />.
 */
export function MonitoringNavLink() {
  return (
    <Link
      to="/monitoring"
      className="inline-flex items-center gap-1.5 text-sm text-zinc-600 underline-offset-4 hover:underline"
    >
      Monitoring
    </Link>
  );
}
