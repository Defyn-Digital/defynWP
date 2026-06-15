import { Link } from 'react-router-dom';

/**
 * P4.2 — nav link to /security (no badge — security has no active-count concept
 * in the header; the fleet page itself shows the summary).
 * Rendered in the Overview header beside <MonitoringNavLink /> and <JobsNavLink />.
 */
export function SecurityNavLink() {
  return (
    <Link
      to="/security"
      className="inline-flex items-center gap-1.5 text-sm text-zinc-600 underline-offset-4 hover:underline"
    >
      Security
    </Link>
  );
}
