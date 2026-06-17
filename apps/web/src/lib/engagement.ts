export function formatEngagement(seconds: number): string {
  const total = Math.floor(seconds);
  return `${Math.floor(total / 60)}m ${total % 60}s`;
}
