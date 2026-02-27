import { describe, expect, it } from 'vitest';

import {
  DEFAULT_ONLINE_THRESHOLD_SECONDS,
  isServerOnline,
} from './online-status.util';

describe('isServerOnline', () => {
  it('returns false when lastHeartbeat is null', () => {
    expect(isServerOnline(null)).toBe(false);
  });

  it('returns true when lastHeartbeat is within threshold', () => {
    const recentHeartbeat = new Date(Date.now() - 60_000); // 60 seconds ago
    expect(isServerOnline(recentHeartbeat, 120)).toBe(true);
  });

  it('returns false when lastHeartbeat is older than threshold', () => {
    const oldHeartbeat = new Date(Date.now() - 400_000); // ~6.7 minutes ago
    expect(isServerOnline(oldHeartbeat, DEFAULT_ONLINE_THRESHOLD_SECONDS)).toBe(
      false,
    );
  });

  it('returns true when lastHeartbeat is exactly at threshold boundary', () => {
    // Just under the threshold: should be online
    const heartbeat = new Date(Date.now() - 359_900); // 359.9 seconds ago
    expect(isServerOnline(heartbeat, 360)).toBe(true);
  });

  it('returns false when lastHeartbeat is just over threshold', () => {
    const heartbeat = new Date(Date.now() - 361_000); // 361 seconds ago
    expect(isServerOnline(heartbeat, 360)).toBe(false);
  });

  it('uses DEFAULT_ONLINE_THRESHOLD_SECONDS when threshold not specified', () => {
    const recentHeartbeat = new Date(
      Date.now() - (DEFAULT_ONLINE_THRESHOLD_SECONDS - 10) * 1000,
    );
    expect(isServerOnline(recentHeartbeat)).toBe(true);
  });
});
