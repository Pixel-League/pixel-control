import { NotFoundException } from '@nestjs/common';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { StatsReadService } from './stats-read.service';

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
});
