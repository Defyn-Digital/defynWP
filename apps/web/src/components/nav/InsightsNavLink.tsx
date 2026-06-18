import { Link } from 'react-router-dom';

/**
 * P6.3 — nav link to /insights (combined fleet Performance + Analytics).
 * Rendered in the Overview header beside <SecurityNavLink />.
 */
export function InsightsNavLink() {
  return (
    <Link
      to="/insights"
      className="inline-flex items-center gap-1.5 text-sm text-zinc-600 underline-offset-4 hover:underline"
    >
      Insights
    </Link>
  );
}
