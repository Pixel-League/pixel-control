import { NotFoundException } from '@nestjs/common';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { EliteStatsReadService } from './elite-stats-read.service';

// ---------------------------------------------------------------------------
// Factory helpers
// ---------------------------------------------------------------------------

let seq = 0;

const makeClutch = (overrides: Partial<{
  is_clutch: boolean;
  clutch_player_login: string | null;
  alive_defenders_at_end: number;
  total_defenders: number;
}> = {}) => ({
  is_clutch: false,
  clutch_player_login: null,
  alive_defenders_at_end: 3,
  total_defenders: 3,
  ...overrides,
});

const makeEliteTurnSummaryPayload = (overrides: {
  turn_number?: number;
  attacker_login?: string;
  defender_logins?: string[];
  attacker_team_id?: number | null;
  outcome?: string;
  duration_seconds?: number;
  defense_success?: boolean;
  per_player_stats?: Record<string, { kills: number; deaths: number; hits: number; shots: number; misses: number; rocket_hits: number }>;
  map_uid?: string;
  map_name?: string;
  clutch?: ReturnType<typeof makeClutch>;
} = {}) => ({
  event_kind: 'elite_turn_summary' as const,
  turn_number: overrides.turn_number ?? 1,
  attacker_login: overrides.attacker_login ?? 'attacker1',
  defender_logins: overrides.defender_logins ?? ['def1', 'def2', 'def3'],
  attacker_team_id: overrides.attacker_team_id !== undefined ? overrides.attacker_team_id : 0,
  outcome: overrides.outcome ?? 'time_limit',
  duration_seconds: overrides.duration_seconds ?? 45,
  defense_success: overrides.defense_success !== undefined ? overrides.defense_success : true,
  per_player_stats: overrides.per_player_stats ?? {
    attacker1: { kills: 2, deaths: 0, hits: 4, shots: 6, misses: 2, rocket_hits: 2 },
    def1: { kills: 0, deaths: 1, hits: 0, shots: 1, misses: 1, rocket_hits: 0 },
    def2: { kills: 0, deaths: 1, hits: 1, shots: 2, misses: 1, rocket_hits: 1 },
    def3: { kills: 0, deaths: 0, hits: 0, shots: 1, misses: 1, rocket_hits: 0 },
  },
  map_uid: overrides.map_uid ?? 'uid-alpha',
  map_name: overrides.map_name ?? 'Alpha Arena',
  clutch: overrides.clutch ?? makeClutch(),
});

const makeEliteTurnEvent = (overrides: {
  turn_number?: number;
  attacker_login?: string;
  defender_logins?: string[];
  outcome?: string;
  defense_success?: boolean;
  per_player_stats?: Record<string, { kills: number; deaths: number; hits: number; shots: number; misses: number; rocket_hits: number }>;
  map_uid?: string;
  map_name?: string;
  clutch?: ReturnType<typeof makeClutch>;
  sourceTime?: bigint;
} = {}) => {
  seq += 1;
  return {
    id: `evt-${seq}`,
    eventId: `pc-evt-combat-elite-${seq}`,
    eventName: 'pixel_control.combat.elite_turn_summary',
    eventCategory: 'combat',
    sourceCallback: 'SM_ELITE_END_TURN',
    sourceTime: overrides.sourceTime ?? BigInt(seq * 1_000_000),
    idempotencyKey: `idem-elite-${seq}`,
    schemaVersion: '2026-02-20.1',
    receivedAt: new Date('2026-03-01T10:00:00Z'),
    metadata: null,
    payload: makeEliteTurnSummaryPayload(overrides),
  };
};

const makeNonEliteEvent = () => ({
  id: `evt-non-elite-${seq + 1}`,
  eventId: `pc-evt-combat-${seq + 1}`,
  eventCategory: 'combat',
  sourceTime: BigInt(999_000),
  payload: { event_kind: 'onshoot', player_counters: {} },
});

const makeServer = (overrides = {}) => ({
  id: 'server-uuid',
  serverLogin: 'test-server',
  ...overrides,
});

// ---------------------------------------------------------------------------
// Mocks
// ---------------------------------------------------------------------------

const makeServerResolver = () => ({
  resolve: vi.fn().mockResolvedValue({ server: makeServer(), online: true }),
});

const makeElitePrisma = (events: ReturnType<typeof makeEliteTurnEvent>[] = []) => ({
  event: {
    findMany: vi.fn().mockResolvedValue(events),
    findFirst: vi.fn().mockResolvedValue(events[0] ?? null),
  },
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('EliteStatsReadService', () => {
  let service: EliteStatsReadService;
  let prisma: ReturnType<typeof makeElitePrisma>;
  let serverResolver: ReturnType<typeof makeServerResolver>;

  beforeEach(() => {
    seq = 0;
    serverResolver = makeServerResolver();
    prisma = makeElitePrisma();
    service = new EliteStatsReadService(serverResolver as never, prisma as never);
  });

  // ---------------------------------------------------------------------------
  // getEliteTurns
  // ---------------------------------------------------------------------------

  describe('getEliteTurns', () => {
    it('returns empty turns list when no events exist', async () => {
      prisma.event.findMany.mockResolvedValue([]);
      const result = await service.getEliteTurns('test-server', 50, 0);
      expect(result.server_login).toBe('test-server');
      expect(result.turns).toHaveLength(0);
      expect(result.pagination.total).toBe(0);
    });

    it('returns only elite_turn_summary events (filters out non-elite combat events)', async () => {
      const eliteEvt = makeEliteTurnEvent({ turn_number: 1 });
      const nonElite = makeNonEliteEvent();
      prisma.event.findMany.mockResolvedValue([eliteEvt, nonElite]);
      const result = await service.getEliteTurns('test-server', 50, 0);
      expect(result.turns).toHaveLength(1);
      expect(result.turns[0].turn_number).toBe(1);
    });

    it('returns multiple turns with correct pagination', async () => {
      const events = [
        makeEliteTurnEvent({ turn_number: 3 }),
        makeEliteTurnEvent({ turn_number: 2 }),
        makeEliteTurnEvent({ turn_number: 1 }),
      ];
      prisma.event.findMany.mockResolvedValue(events);
      const result = await service.getEliteTurns('test-server', 2, 0);
      expect(result.turns).toHaveLength(2);
      expect(result.pagination.total).toBe(3);
      expect(result.pagination.limit).toBe(2);
      expect(result.pagination.offset).toBe(0);
    });

    it('applies offset correctly', async () => {
      const events = [
        makeEliteTurnEvent({ turn_number: 3 }),
        makeEliteTurnEvent({ turn_number: 2 }),
        makeEliteTurnEvent({ turn_number: 1 }),
      ];
      prisma.event.findMany.mockResolvedValue(events);
      const result = await service.getEliteTurns('test-server', 2, 1);
      expect(result.turns).toHaveLength(2);
      expect(result.turns[0].turn_number).toBe(2);
      expect(result.turns[1].turn_number).toBe(1);
    });

    it('throws NotFoundException when server not found', async () => {
      serverResolver.resolve.mockRejectedValue(new NotFoundException('Server not found'));
      await expect(service.getEliteTurns('unknown-server', 50, 0)).rejects.toThrow(NotFoundException);
    });
  });

  // ---------------------------------------------------------------------------
  // getEliteTurnByNumber
  // ---------------------------------------------------------------------------

  describe('getEliteTurnByNumber', () => {
    it('returns the correct turn by turn number', async () => {
      const event = makeEliteTurnEvent({ turn_number: 5, outcome: 'capture' });
      prisma.event.findMany.mockResolvedValue([event]);
      const result = await service.getEliteTurnByNumber('test-server', 5);
      expect(result.turn_number).toBe(5);
      expect(result.outcome).toBe('capture');
      expect(result.server_login).toBe('test-server');
      expect(result.event_id).toBe(event.eventId);
      expect(result.recorded_at).toBeDefined();
    });

    it('throws NotFoundException when turn number not found', async () => {
      prisma.event.findMany.mockResolvedValue([]);
      await expect(service.getEliteTurnByNumber('test-server', 99)).rejects.toThrow(NotFoundException);
    });

    it('returns most recent event when multiple events share the same turn number', async () => {
      const older = makeEliteTurnEvent({ turn_number: 1, outcome: 'time_limit', sourceTime: BigInt(1000) });
      const newer = makeEliteTurnEvent({ turn_number: 1, outcome: 'capture', sourceTime: BigInt(2000) });
      // findMany returns most-recent first (desc ordering)
      prisma.event.findMany.mockResolvedValue([newer, older]);
      const result = await service.getEliteTurnByNumber('test-server', 1);
      expect(result.outcome).toBe('capture');
    });
  });

  // ---------------------------------------------------------------------------
  // getPlayerClutchStats
  // ---------------------------------------------------------------------------

  describe('getPlayerClutchStats', () => {
    it('returns zeroed stats when player has no defense rounds', async () => {
      // Events where player is not in defender_logins
      const event = makeEliteTurnEvent({
        attacker_login: 'attacker1',
        defender_logins: ['other1', 'other2'],
      });
      prisma.event.findMany.mockResolvedValue([event]);
      const result = await service.getPlayerClutchStats('test-server', 'player-not-here');
      expect(result.clutch_count).toBe(0);
      expect(result.total_defense_rounds).toBe(0);
      expect(result.clutch_rate).toBe(0);
      expect(result.clutch_turns).toHaveLength(0);
    });

    it('counts defense rounds where player was a defender', async () => {
      const events = [
        makeEliteTurnEvent({ defender_logins: ['def1', 'def2'], clutch: makeClutch({ is_clutch: false }) }),
        makeEliteTurnEvent({ defender_logins: ['def1', 'def3'], clutch: makeClutch({ is_clutch: false }) }),
        makeEliteTurnEvent({ defender_logins: ['def2', 'def3'], clutch: makeClutch({ is_clutch: false }) }),
      ];
      prisma.event.findMany.mockResolvedValue(events);
      const result = await service.getPlayerClutchStats('test-server', 'def1');
      expect(result.total_defense_rounds).toBe(2);
      expect(result.clutch_count).toBe(0);
    });

    it('detects clutch turns and computes clutch_rate', async () => {
      const events = [
        makeEliteTurnEvent({
          turn_number: 1,
          defender_logins: ['def1', 'def2', 'def3'],
          defense_success: true,
          clutch: makeClutch({ is_clutch: true, clutch_player_login: 'def1', alive_defenders_at_end: 1, total_defenders: 3 }),
        }),
        makeEliteTurnEvent({
          turn_number: 2,
          defender_logins: ['def1', 'def2'],
          defense_success: false,
          clutch: makeClutch({ is_clutch: false }),
        }),
        makeEliteTurnEvent({
          turn_number: 3,
          defender_logins: ['def1', 'def2', 'def3'],
          defense_success: true,
          clutch: makeClutch({ is_clutch: true, clutch_player_login: 'def1', alive_defenders_at_end: 1, total_defenders: 3 }),
        }),
      ];
      prisma.event.findMany.mockResolvedValue(events);
      const result = await service.getPlayerClutchStats('test-server', 'def1');
      expect(result.clutch_count).toBe(2);
      expect(result.total_defense_rounds).toBe(3);
      expect(result.clutch_rate).toBeCloseTo(0.6667, 3);
      expect(result.clutch_turns).toHaveLength(2);
      expect(result.clutch_turns[0].turn_number).toBe(1);
      expect(result.clutch_turns[1].turn_number).toBe(3);
    });

    it('does not count clutches by other players', async () => {
      const event = makeEliteTurnEvent({
        defender_logins: ['def1', 'def2', 'def3'],
        defense_success: true,
        clutch: makeClutch({ is_clutch: true, clutch_player_login: 'def2', alive_defenders_at_end: 1, total_defenders: 3 }),
      });
      prisma.event.findMany.mockResolvedValue([event]);
      const result = await service.getPlayerClutchStats('test-server', 'def1');
      expect(result.clutch_count).toBe(0);
      expect(result.total_defense_rounds).toBe(1);
    });
  });

  // ---------------------------------------------------------------------------
  // getElitePlayerTurnHistory
  // ---------------------------------------------------------------------------

  describe('getElitePlayerTurnHistory', () => {
    it('returns empty data when player has no turns', async () => {
      const event = makeEliteTurnEvent({
        attacker_login: 'other',
        defender_logins: ['other1', 'other2'],
      });
      prisma.event.findMany.mockResolvedValue([event]);
      const result = await service.getElitePlayerTurnHistory('test-server', 'player-unknown', 50, 0);
      expect(result.data).toHaveLength(0);
      expect(result.pagination.total).toBe(0);
    });

    it('includes turns where player is the attacker with role=attacker', async () => {
      const event = makeEliteTurnEvent({
        attacker_login: 'attacker1',
        defender_logins: ['def1', 'def2'],
        per_player_stats: {
          attacker1: { kills: 2, deaths: 0, hits: 4, shots: 6, misses: 2, rocket_hits: 2 },
          def1: { kills: 0, deaths: 1, hits: 0, shots: 1, misses: 1, rocket_hits: 0 },
          def2: { kills: 0, deaths: 1, hits: 0, shots: 1, misses: 1, rocket_hits: 0 },
        },
      });
      prisma.event.findMany.mockResolvedValue([event]);
      const result = await service.getElitePlayerTurnHistory('test-server', 'attacker1', 50, 0);
      expect(result.data).toHaveLength(1);
      expect(result.data[0].role).toBe('attacker');
      expect(result.data[0].stats.kills).toBe(2);
    });

    it('includes turns where player is a defender with role=defender', async () => {
      const event = makeEliteTurnEvent({
        attacker_login: 'attacker1',
        defender_logins: ['def1', 'def2'],
        per_player_stats: {
          attacker1: { kills: 2, deaths: 0, hits: 4, shots: 6, misses: 2, rocket_hits: 2 },
          def1: { kills: 0, deaths: 1, hits: 0, shots: 1, misses: 1, rocket_hits: 0 },
          def2: { kills: 0, deaths: 0, hits: 1, shots: 2, misses: 1, rocket_hits: 1 },
        },
      });
      prisma.event.findMany.mockResolvedValue([event]);
      const result = await service.getElitePlayerTurnHistory('test-server', 'def2', 50, 0);
      expect(result.data).toHaveLength(1);
      expect(result.data[0].role).toBe('defender');
      expect(result.data[0].stats.hits).toBe(1);
    });

    it('applies pagination correctly', async () => {
      const events = [
        makeEliteTurnEvent({ turn_number: 3, attacker_login: 'p1', defender_logins: ['def1'] }),
        makeEliteTurnEvent({ turn_number: 2, attacker_login: 'p1', defender_logins: ['def1'] }),
        makeEliteTurnEvent({ turn_number: 1, attacker_login: 'p1', defender_logins: ['def1'] }),
      ];
      prisma.event.findMany.mockResolvedValue(events);
      const result = await service.getElitePlayerTurnHistory('test-server', 'p1', 2, 1);
      expect(result.data).toHaveLength(2);
      expect(result.data[0].turn_number).toBe(2);
      expect(result.data[1].turn_number).toBe(1);
      expect(result.pagination.total).toBe(3);
    });

    it('uses zero stats when player has no per_player_stats entry', async () => {
      const event = makeEliteTurnEvent({
        attacker_login: 'attacker1',
        defender_logins: ['def1', 'def2'],
        per_player_stats: {},
      });
      prisma.event.findMany.mockResolvedValue([event]);
      const result = await service.getElitePlayerTurnHistory('test-server', 'def1', 50, 0);
      expect(result.data).toHaveLength(1);
      expect(result.data[0].stats.kills).toBe(0);
      expect(result.data[0].stats.deaths).toBe(0);
    });

    it('includes server_login and player_login in response', async () => {
      prisma.event.findMany.mockResolvedValue([]);
      const result = await service.getElitePlayerTurnHistory('test-server', 'def1', 50, 0);
      expect(result.server_login).toBe('test-server');
      expect(result.player_login).toBe('def1');
    });
  });
});
