export const DEFAULT_ONLINE_THRESHOLD_SECONDS = 360;

/**
 * Returns true if the server's last heartbeat was received within thresholdSeconds of now.
 * Returns false if lastHeartbeat is null or older than the threshold.
 */
export function isServerOnline(
  lastHeartbeat: Date | null,
  thresholdSeconds: number = DEFAULT_ONLINE_THRESHOLD_SECONDS,
): boolean {
  if (!lastHeartbeat) {
    return false;
  }

  const nowMs = Date.now();
  const lastHeartbeatMs = lastHeartbeat.getTime();
  const diffSeconds = (nowMs - lastHeartbeatMs) / 1000;

  return diffSeconds <= thresholdSeconds;
}
