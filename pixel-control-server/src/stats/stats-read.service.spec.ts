import { NotFoundException } from '@nestjs/common';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { StatsReadService } from './stats-read.service';

// ---------------------------------------------------------------------------
// Factory helpers for lifecycle events (used by per-map / per-series tests)
// ---------------------------------------------------------------------------

let lifecycleSeq = 0;

const makeLifecycleEvent = (overrides: {
  variant: string;
  sourceTime?: bigint;
  mapUid?: string;
  mapName?: string;
  aggregateStats?: {
    scope?: string;
    player_counters_delta?: Record<string, {
      kills?: number; deaths?: number; hits?: number; shots?: number;
      misses?: number; rockets?: number; lasers?: number; accuracy?: number;
    }>;
    team_counters_delta?: unknown[];
    totals?: Record<string, number>;
    win_context?: Record<string, unknown>;
    window?: { duration_seconds?: number; started_at?: number; ended_at?: number };
  };
}) => {
  lifecycleSeq += 1;
  return {
    id: `evt-lc-${lifecycleSeq}`,
    eventId: `pc-evt-lifecycle-${lifecycleSeq}`,
    eventName: `pixel_control.lifecycle.event_${lifecycleSeq}`,
    eventCategory: 'lifecycle',
    sourceCallback: 'LIFECYCLE',
    sourceTime: overrides.sourceTime ?? BigInt(lifecycleSeq * 1_000),
    idempotencyKey: `idem-lc-${lifecycleSeq}`,
    schemaVersion: '2026-02-20.1',
    receivedAt: new Date('2026-02-28T10:00:00Z'),
    metadata: null,
    payload: {
      variant: overrides.variant,
      ...(overrides.mapUid !== undefined
        ? {
            map_rotation: {
              current_map: {
                uid: overrides.mapUid,
                name: overrides.mapName ?? overrides.mapUid,
              },
            },
          }
        : {}),
      ...(overrides.aggregateStats !== undefined
        ? { aggregate_stats: overrides.aggregateStats }
        : {}),
    },
  };
};

const makeServer = (overrides = {}) => ({
  id: 'server-uuid',
  serverLogin: 'test-server',
  ...overrides,
});

const makeCombatEvent = (overrides: {
  eventKind?: string;
  sourceTime?: bigint;
  playerCounters?: Record<string, {
    kills?: number; deaths?: number; hits?: number; shots?: number;
    misses?: number; rockets?: number; lasers?: number;
  }>;
  scoresSection?: string;
  scoresSnapshot?: Record<string, unknown>;
  scoresResult?: Record<string, unknown>;
}) => ({
  id: 'evt-combat',
  eventId: 'pc-evt-combat-1',
  eventName: 'pixel_control.combat.event',
  eventCategory: 'combat',
  sourceCallback: 'SM_COMBAT',
  sourceTime: overrides.sourceTime ?? BigInt(1000000),
  idempotencyKey: 'idem-combat-1',
  schemaVersion: '2026-02-20.1',
  receivedAt: new Date('2026-02-28T10:00:00Z'),
  metadata: null,
  payload: {
    event_kind: overrides.eventKind ?? 'onshoot',
    player_counters: overrides.playerCounters ?? {
      player1: { kills: 10, deaths: 5, hits: 50, shots: 200, misses: 150, rockets: 100, lasers: 100 },
      player2: { kills: 8, deaths: 3, hits: 30, shots: 100, misses: 70, rockets: 50, lasers: 50 },
    },
    scores_section: overrides.scoresSection ?? undefined,
    scores_snapshot: overrides.scoresSnapshot ?? undefined,
    scores_result: overrides.scoresResult ?? undefined,
  },
});

const makeResolverStub = (server = makeServer()) => ({
  resolve: vi.fn().mockResolvedValue({ server, online: true }),
});

const makePrismaStub = () => ({
  event: {
    findMany: vi.fn().mockResolvedValue([]),
    findFirst: vi.fn().mockResolvedValue(null),
  },
});

describe('StatsReadService', () => {
  let service: StatsReadService;
  let resolver: ReturnType<typeof makeResolverStub>;
  let prisma: ReturnType<typeof makePrismaStub>;

  beforeEach(() => {
    resolver = makeResolverStub();
    prisma = makePrismaStub();
    service = new StatsReadService(resolver as never, prisma as never);
  });

  describe('getCombatStats', () => {
    it('returns zero stats when no combat events', async () => {
      prisma.event.findMany.mockResolvedValue([]);

      const result = await service.getCombatStats('test-server');

      expect(result.combat_summary.total_events).toBe(0);
      expect(result.combat_summary.total_kills).toBe(0);
      expect(result.combat_summary.tracked_player_count).toBe(0);
    });

    it('aggregates player counters from latest combat event', async () => {
      prisma.event.findMany.mockResolvedValue([makeCombatEvent({})]);

      const result = await service.getCombatStats('test-server');

      expect(result.combat_summary.total_kills).toBe(18); // 10 + 8
      expect(result.combat_summary.total_deaths).toBe(8); // 5 + 3
      expect(result.combat_summary.total_hits).toBe(80); // 50 + 30
      expect(result.combat_summary.total_shots).toBe(300); // 200 + 100
      expect(result.combat_summary.tracked_player_count).toBe(2);
    });

    it('computes accuracy correctly', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeCombatEvent({ playerCounters: { p1: { hits: 50, shots: 200 } } }),
      ]);

      const result = await service.getCombatStats('test-server');

      expect(result.combat_summary.total_accuracy).toBeCloseTo(0.25);
    });

    it('counts event kinds breakdown', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeCombatEvent({ eventKind: 'onshoot', sourceTime: BigInt(3000000) }),
        makeCombatEvent({ eventKind: 'onshoot', sourceTime: BigInt(2000000) }),
        makeCombatEvent({ eventKind: 'onhit', sourceTime: BigInt(1000000) }),
      ]);

      const result = await service.getCombatStats('test-server');

      expect(result.combat_summary.event_kinds['onshoot']).toBe(2);
      expect(result.combat_summary.event_kinds['onhit']).toBe(1);
    });

    it('returns time_range with since/until', async () => {
      prisma.event.findMany.mockResolvedValue([]);

      const result = await service.getCombatStats(
        'test-server',
        '2026-02-28T09:00:00Z',
        '2026-02-28T10:00:00Z',
      );

      expect(result.time_range.since).toBe('2026-02-28T09:00:00Z');
      expect(result.time_range.until).toBe('2026-02-28T10:00:00Z');
    });
  });

  describe('getCombatPlayersCounters', () => {
    it('returns empty list when no combat events', async () => {
      prisma.event.findFirst.mockResolvedValue(null);

      const result = await service.getCombatPlayersCounters('test-server', 50, 0);

      expect(result.data).toHaveLength(0);
    });

    it('returns per-player counters from latest event', async () => {
      prisma.event.findFirst.mockResolvedValue(makeCombatEvent({}));

      const result = await service.getCombatPlayersCounters('test-server', 50, 0);

      expect(result.pagination.total).toBe(2);
      const p1 = result.data.find((p) => p.login === 'player1');
      expect(p1!.kills).toBe(10);
      expect(p1!.deaths).toBe(5);
    });

    it('applies pagination', async () => {
      prisma.event.findFirst.mockResolvedValue(makeCombatEvent({}));

      const result = await service.getCombatPlayersCounters('test-server', 1, 0);

      expect(result.data).toHaveLength(1);
      expect(result.pagination.total).toBe(2);
    });
  });

  describe('getPlayerCombatCounters', () => {
    it('returns counters for known player', async () => {
      prisma.event.findMany.mockResolvedValue([makeCombatEvent({})]);

      const result = await service.getPlayerCombatCounters('test-server', 'player1');

      expect(result.login).toBe('player1');
      expect(result.counters.kills).toBe(10);
    });

    it('throws NotFoundException for player with no combat data', async () => {
      prisma.event.findMany.mockResolvedValue([makeCombatEvent({})]);

      await expect(
        service.getPlayerCombatCounters('test-server', 'unknown-player'),
      ).rejects.toThrow(NotFoundException);
    });

    it('returns zero counters when player has zero values', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeCombatEvent({
          playerCounters: { zeroPlayer: { kills: 0, deaths: 0, hits: 0, shots: 0 } },
        }),
      ]);

      const result = await service.getPlayerCombatCounters('test-server', 'zeroPlayer');

      expect(result.counters.kills).toBe(0);
      expect(result.counters.accuracy).toBe(0);
    });
  });

  describe('getLatestScores', () => {
    it('returns no_scores_available when no scores event', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeCombatEvent({ eventKind: 'onshoot' }),
      ]);

      const result = await service.getLatestScores('test-server');

      expect(result.no_scores_available).toBe(true);
      expect(result.scores_section).toBeNull();
    });

    it('returns latest scores snapshot', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeCombatEvent({
          eventKind: 'scores',
          scoresSection: 'EndRound',
          scoresSnapshot: { teams: [], players: [] },
          scoresResult: { result_state: 'team_win', winning_side: 'team_a' },
        }),
      ]);

      const result = await service.getLatestScores('test-server');

      expect(result.scores_section).toBe('EndRound');
      expect(result.scores_snapshot).toEqual({ teams: [], players: [] });
      expect(result.scores_result).toEqual({ result_state: 'team_win', winning_side: 'team_a' });
      expect(result.event_id).toBe('pc-evt-combat-1');
    });
  });

  // -------------------------------------------------------------------------
  // P2.5 per-map / per-series combat stats
  // -------------------------------------------------------------------------

  const makeMapEndEvent = (
    mapUid: string,
    mapName: string,
    sourceTime: bigint,
    playerCounters: Record<string, {
      kills?: number; deaths?: number; hits?: number; shots?: number;
      misses?: number; rockets?: number; lasers?: number; accuracy?: number;
    }> = {},
    totals: Record<string, number> = {},
    winContext: Record<string, unknown> = {},
  ) =>
    makeLifecycleEvent({
      variant: 'map.end',
      sourceTime,
      mapUid,
      mapName,
      aggregateStats: {
        scope: 'map',
        player_counters_delta: playerCounters,
        team_counters_delta: [],
        totals,
        win_context: winContext,
        window: { duration_seconds: 120 },
      },
    });

  describe('getMapCombatStatsList', () => {
    beforeEach(() => { lifecycleSeq = 0; });

    it('returns empty maps array when no lifecycle events', async () => {
      prisma.event.findMany.mockResolvedValue([]);

      const result = await service.getMapCombatStatsList('test-server', 50, 0);

      expect(result.maps).toHaveLength(0);
      expect(result.pagination.total).toBe(0);
      expect(result.server_login).toBe('test-server');
    });

    it('returns map entries from map.end events with scope=map', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeMapEndEvent('uid-alpha', 'Alpha Arena', BigInt(2_000_000), { p1: { kills: 5, shots: 100 } }, { kills: 5 }),
      ]);

      const result = await service.getMapCombatStatsList('test-server', 50, 0);

      expect(result.maps).toHaveLength(1);
      expect(result.maps[0].map_uid).toBe('uid-alpha');
      expect(result.maps[0].map_name).toBe('Alpha Arena');
      expect(result.maps[0].player_stats['p1'].kills).toBe(5);
      expect(result.maps[0].totals.kills).toBe(5);
    });

    it('ignores lifecycle events that are not map.end', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeLifecycleEvent({ variant: 'round.end', sourceTime: BigInt(1_000_000) }),
        makeLifecycleEvent({ variant: 'map.begin', sourceTime: BigInt(500_000) }),
        makeMapEndEvent('uid-beta', 'Beta Arena', BigInt(2_000_000)),
      ]);

      const result = await service.getMapCombatStatsList('test-server', 50, 0);

      expect(result.maps).toHaveLength(1);
      expect(result.maps[0].map_uid).toBe('uid-beta');
    });

    it('ignores map.end events without aggregate_stats', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeLifecycleEvent({ variant: 'map.end', sourceTime: BigInt(1_000_000), mapUid: 'uid-no-agg' }),
      ]);

      const result = await service.getMapCombatStatsList('test-server', 50, 0);

      expect(result.maps).toHaveLength(0);
    });

    it('ignores map.end events without aggregate_stats.scope=map', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeLifecycleEvent({
          variant: 'map.end',
          sourceTime: BigInt(1_000_000),
          mapUid: 'uid-wrong-scope',
          aggregateStats: { scope: 'round' },
        }),
      ]);

      const result = await service.getMapCombatStatsList('test-server', 50, 0);

      expect(result.maps).toHaveLength(0);
    });

    it('applies pagination (limit)', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeMapEndEvent('uid-a', 'Map A', BigInt(3_000_000)),
        makeMapEndEvent('uid-b', 'Map B', BigInt(2_000_000)),
        makeMapEndEvent('uid-c', 'Map C', BigInt(1_000_000)),
      ]);

      const result = await service.getMapCombatStatsList('test-server', 2, 0);

      expect(result.maps).toHaveLength(2);
      expect(result.pagination.total).toBe(3);
      expect(result.pagination.limit).toBe(2);
    });

    it('applies pagination (offset)', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeMapEndEvent('uid-a', 'Map A', BigInt(3_000_000)),
        makeMapEndEvent('uid-b', 'Map B', BigInt(2_000_000)),
        makeMapEndEvent('uid-c', 'Map C', BigInt(1_000_000)),
      ]);

      const result = await service.getMapCombatStatsList('test-server', 2, 1);

      expect(result.maps).toHaveLength(2);
      expect(result.maps[0].map_uid).toBe('uid-b');
    });

    it('extracts win_context and duration_seconds', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeMapEndEvent('uid-x', 'X Map', BigInt(1_000_000), {}, {}, { winner_team_id: 0 }),
      ]);

      const result = await service.getMapCombatStatsList('test-server', 50, 0);

      expect(result.maps[0].win_context).toEqual({ winner_team_id: 0 });
      expect(result.maps[0].duration_seconds).toBe(120);
    });
  });

  describe('getMapCombatStats (single map by UID)', () => {
    beforeEach(() => { lifecycleSeq = 0; });

    it('returns stats for matching map_uid', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeMapEndEvent('uid-alpha', 'Alpha', BigInt(1_000_000), { p1: { kills: 3 } }),
      ]);

      const result = await service.getMapCombatStats('test-server', 'uid-alpha');

      expect(result.map_uid).toBe('uid-alpha');
      expect(result.player_stats['p1'].kills).toBe(3);
    });

    it('throws NotFoundException when map UID not found', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeMapEndEvent('uid-alpha', 'Alpha', BigInt(1_000_000)),
      ]);

      await expect(
        service.getMapCombatStats('test-server', 'uid-missing'),
      ).rejects.toThrow(NotFoundException);
    });

    it('returns the latest occurrence when multiple map.end events exist for the same UID', async () => {
      // Prisma returns desc order, so latest is first
      prisma.event.findMany.mockResolvedValue([
        makeMapEndEvent('uid-alpha', 'Alpha v2', BigInt(3_000_000), { p1: { kills: 9 } }),
        makeMapEndEvent('uid-alpha', 'Alpha v1', BigInt(1_000_000), { p1: { kills: 2 } }),
      ]);

      const result = await service.getMapCombatStats('test-server', 'uid-alpha');

      expect(result.player_stats['p1'].kills).toBe(9);
      expect(result.map_name).toBe('Alpha v2');
    });
  });

  describe('getMapPlayerCombatStats (single player on map)', () => {
    beforeEach(() => { lifecycleSeq = 0; });

    it('returns player counters when player exists', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeMapEndEvent('uid-alpha', 'Alpha', BigInt(1_000_000), {
          player1: { kills: 7, deaths: 2, hits: 40, shots: 80, misses: 40, rockets: 30, lasers: 10, accuracy: 0.5 },
        }),
      ]);

      const result = await service.getMapPlayerCombatStats('test-server', 'uid-alpha', 'player1');

      expect(result.player_login).toBe('player1');
      expect(result.map_uid).toBe('uid-alpha');
      expect(result.counters.kills).toBe(7);
      expect(result.counters.accuracy).toBe(0.5);
    });

    it('throws NotFoundException when player not found on map', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeMapEndEvent('uid-alpha', 'Alpha', BigInt(1_000_000), { known_player: { kills: 1 } }),
      ]);

      await expect(
        service.getMapPlayerCombatStats('test-server', 'uid-alpha', 'unknown_player'),
      ).rejects.toThrow(NotFoundException);
    });

    it('throws NotFoundException when map UID not found', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeMapEndEvent('uid-alpha', 'Alpha', BigInt(1_000_000)),
      ]);

      await expect(
        service.getMapPlayerCombatStats('test-server', 'uid-missing', 'player1'),
      ).rejects.toThrow(NotFoundException);
    });
  });

  describe('getSeriesCombatStatsList', () => {
    beforeEach(() => { lifecycleSeq = 0; });

    it('returns empty series array when no match begin/end events', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeMapEndEvent('uid-alpha', 'Alpha', BigInt(1_000_000)),
      ]);

      const result = await service.getSeriesCombatStatsList('test-server', 50, 0);

      expect(result.series).toHaveLength(0);
      expect(result.pagination.total).toBe(0);
    });

    it('correctly pairs match.begin and match.end events into one series', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeLifecycleEvent({ variant: 'match.begin', sourceTime: BigInt(1_000_000) }),
        makeMapEndEvent('uid-alpha', 'Alpha', BigInt(2_000_000), { p1: { kills: 5 } }, { kills: 5 }),
        makeMapEndEvent('uid-beta', 'Beta', BigInt(3_000_000), { p1: { kills: 3 } }, { kills: 3 }),
        makeLifecycleEvent({ variant: 'match.end', sourceTime: BigInt(4_000_000) }),
      ]);

      const result = await service.getSeriesCombatStatsList('test-server', 50, 0);

      expect(result.series).toHaveLength(1);
      expect(result.series[0].total_maps_played).toBe(2);
      expect(result.series[0].maps[0].map_uid).toBe('uid-alpha');
      expect(result.series[0].maps[1].map_uid).toBe('uid-beta');
    });

    it('includes only map.end events within the series time window', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeMapEndEvent('uid-before', 'Before', BigInt(500_000)),
        makeLifecycleEvent({ variant: 'match.begin', sourceTime: BigInt(1_000_000) }),
        makeMapEndEvent('uid-inside', 'Inside', BigInt(2_000_000)),
        makeLifecycleEvent({ variant: 'match.end', sourceTime: BigInt(3_000_000) }),
        makeMapEndEvent('uid-after', 'After', BigInt(4_000_000)),
      ]);

      const result = await service.getSeriesCombatStatsList('test-server', 50, 0);

      expect(result.series[0].maps).toHaveLength(1);
      expect(result.series[0].maps[0].map_uid).toBe('uid-inside');
    });

    it('computes series_totals by summing all map totals', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeLifecycleEvent({ variant: 'match.begin', sourceTime: BigInt(1_000_000) }),
        makeMapEndEvent('uid-alpha', 'Alpha', BigInt(2_000_000), {}, { kills: 10, deaths: 5 }),
        makeMapEndEvent('uid-beta', 'Beta', BigInt(3_000_000), {}, { kills: 8, deaths: 4 }),
        makeLifecycleEvent({ variant: 'match.end', sourceTime: BigInt(4_000_000) }),
      ]);

      const result = await service.getSeriesCombatStatsList('test-server', 50, 0);

      expect(result.series[0].series_totals.kills).toBe(18);
      expect(result.series[0].series_totals.deaths).toBe(9);
    });

    it('excludes incomplete series (match.begin without match.end)', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeLifecycleEvent({ variant: 'match.begin', sourceTime: BigInt(1_000_000) }),
        makeMapEndEvent('uid-alpha', 'Alpha', BigInt(2_000_000)),
        // no match.end
      ]);

      const result = await service.getSeriesCombatStatsList('test-server', 50, 0);

      expect(result.series).toHaveLength(0);
    });

    it('applies pagination', async () => {
      // Two complete series
      prisma.event.findMany.mockResolvedValue([
        makeLifecycleEvent({ variant: 'match.begin', sourceTime: BigInt(1_000_000) }),
        makeMapEndEvent('uid-a1', 'A1', BigInt(2_000_000)),
        makeLifecycleEvent({ variant: 'match.end', sourceTime: BigInt(3_000_000) }),
        makeLifecycleEvent({ variant: 'match.begin', sourceTime: BigInt(4_000_000) }),
        makeMapEndEvent('uid-b1', 'B1', BigInt(5_000_000)),
        makeLifecycleEvent({ variant: 'match.end', sourceTime: BigInt(6_000_000) }),
      ]);

      const result = await service.getSeriesCombatStatsList('test-server', 1, 0);

      expect(result.series).toHaveLength(1);
      expect(result.pagination.total).toBe(2);
    });
  });
});
