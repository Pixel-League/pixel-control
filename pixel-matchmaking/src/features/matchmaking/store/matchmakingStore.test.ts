import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { configure } from 'mobx';

configure({ enforceActions: 'never' });

vi.mock('mobx-persist-store', () => ({
  makePersistable: vi.fn().mockResolvedValue(undefined),
}));

// Import after mocks are set up
const { matchmakingStore } = await import('./matchmakingStore');

function mockFetch(response: { count: number }) {
  global.fetch = vi.fn().mockResolvedValue({
    json: vi.fn().mockResolvedValue(response),
  } as unknown as Response);
}

beforeEach(() => {
  matchmakingStore.searching = false;
  matchmakingStore.queueCount = null;
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('matchmakingStore', () => {
  it('has correct initial state', () => {
    expect(matchmakingStore.searching).toBe(false);
    expect(matchmakingStore.queueCount).toBeNull();
  });

  it('startSearch sets searching=true and updates queueCount', async () => {
    mockFetch({ count: 3 });

    await matchmakingStore.startSearch('testlogin');

    expect(matchmakingStore.searching).toBe(true);
    expect(matchmakingStore.queueCount).toBe(3);
    expect(fetch).toHaveBeenCalledWith(
      '/api/matchmaking/queue',
      expect.objectContaining({ method: 'POST' }),
    );

    // Cleanup polling
    await matchmakingStore.cancelSearch('testlogin');
  });

  it('cancelSearch sets searching=false and clears queueCount', async () => {
    mockFetch({ count: 2 });
    await matchmakingStore.startSearch('testlogin');

    mockFetch({ count: 0 });
    await matchmakingStore.cancelSearch('testlogin');

    expect(matchmakingStore.searching).toBe(false);
    expect(matchmakingStore.queueCount).toBeNull();
    expect(fetch).toHaveBeenCalledWith(
      '/api/matchmaking/queue',
      expect.objectContaining({ method: 'DELETE' }),
    );
  });

  it('updateQueueCount updates queueCount', () => {
    matchmakingStore.updateQueueCount(7);
    expect(matchmakingStore.queueCount).toBe(7);
  });
});
