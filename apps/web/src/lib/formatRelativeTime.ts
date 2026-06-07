/**
 * Format a UTC datetime string as a human-readable relative duration
 * relative to the current local time.
 *
 * Server emits UTC strings in `Y-m-d H:i:s` format (e.g. "2026-06-07 13:20:42").
 * This helper parses that into a Date and formats the gap to "now" as:
 *   "just now"        if < 30 seconds
 *   "X seconds ago"   if < 60 seconds
 *   "X minutes ago"   if < 60 minutes
 *   "X hours ago"     if < 24 hours
 *   "X days ago"      otherwise
 *
 * Falls back to the original string if the input is empty / unparseable.
 *
 * Used by the Overview route's "Last refreshed" indicator.
 */
export function formatRelativeTime(utcString: string, now: Date = new Date()): string {
  if (!utcString) {
    return utcString;
  }

  // Parse "YYYY-MM-DD HH:MM:SS" as UTC. JS Date doesn't recognize this
  // format as UTC by default — replace the space with "T" and append "Z".
  const isoUtc = utcString.replace(' ', 'T') + 'Z';
  const then = new Date(isoUtc);
  if (Number.isNaN(then.getTime())) {
    return utcString;
  }

  const diffMs = now.getTime() - then.getTime();
  const seconds = Math.floor(diffMs / 1000);

  if (seconds < 0) {
    // Future timestamp — treat as "just now" rather than negative.
    return 'just now';
  }
  if (seconds < 30) {
    return 'just now';
  }
  if (seconds < 60) {
    return `${seconds} seconds ago`;
  }

  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) {
    return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;
  }

  const hours = Math.floor(minutes / 60);
  if (hours < 24) {
    return `${hours} hour${hours === 1 ? '' : 's'} ago`;
  }

  const days = Math.floor(hours / 24);
  return `${days} day${days === 1 ? '' : 's'} ago`;
}
