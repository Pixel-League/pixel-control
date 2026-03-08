'use client';

import { makeAutoObservable } from 'mobx';
import { makePersistable } from 'mobx-persist-store';

const QUEUE_POLL_INTERVAL = 5000;

async function joinQueue(login: string): Promise<number> {
  const res = await fetch('/api/matchmaking/queue', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ login }),
  });
  const data = await res.json() as { count: number };
  return data.count;
}

async function leaveQueue(login: string): Promise<void> {
  await fetch('/api/matchmaking/queue', {
    method: 'DELETE',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ login }),
  });
}

async function fetchQueueCount(): Promise<number> {
  const res = await fetch('/api/matchmaking/queue');
  const data = await res.json() as { count: number };
  return data.count;
}

class MatchmakingStore {
  searching = false;
  queueCount: number | null = null;
  private _pollingInterval: ReturnType<typeof setInterval> | null = null;

  constructor() {
    makeAutoObservable(this);
    if (typeof window !== 'undefined') {
      makePersistable(this, {
        name: 'MatchmakingStore',
        properties: ['searching'],
        storage: window.localStorage,
      });
    }
  }

  async startSearch(login: string) {
    this.searching = true;
    const count = await joinQueue(login);
    this.updateQueueCount(count);
    this._startPolling();
  }

  async cancelSearch(login: string) {
    this._stopPolling();
    this.searching = false;
    this.queueCount = null;
    await leaveQueue(login);
  }

  updateQueueCount(count: number) {
    this.queueCount = count;
  }

  private _startPolling() {
    this._stopPolling();
    this._pollingInterval = setInterval(async () => {
      const count = await fetchQueueCount();
      this.updateQueueCount(count);
    }, QUEUE_POLL_INTERVAL);
  }

  private _stopPolling() {
    if (this._pollingInterval !== null) {
      clearInterval(this._pollingInterval);
      this._pollingInterval = null;
    }
  }
}

export const matchmakingStore = new MatchmakingStore();
