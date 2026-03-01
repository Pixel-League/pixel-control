import { NotFoundException } from '@nestjs/common';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { EliteStatsReadController } from './elite-stats-read.controller';

// ---------------------------------------------------------------------------
// Factory helpers
// ---------------------------------------------------------------------------

const makeClutch = () => ({
  is_clutch: false,
  clutch_player_login: null,
  alive_defenders_at_end: 3,
  total_defenders: 3,
});

const makeEliteTurnSummary = (overrides: Partial<{ turn_number: number; outcome: string }> = {}) => ({
  event_kind: 'elite_turn_summary' as const,
  turn_number: overrides.turn_number ?? 1,
  attacker_login: 'attacker1',
  defender_logins: ['def1', 'def2', 'def3'],
  attacker_team_id: 0,
  outcome: overrides.outcome ?? 'time_limit',
  duration_seconds: 45,
  defense_success: true,
  per_player_stats: {},
  map_uid: 'uid-alpha',
  map_name: 'Alpha Arena',
  clutch: makeClutch(),
});

const makeEliteTurnDetail = (overrides: Partial<{ turn_number: number; outcome: string }> = {}) => ({
  ...makeEliteTurnSummary(overrides),
  server_login: 'test-server',
  event_id: 'pc-evt-elite-1',
  recorded_at: '2026-03-01T10:00:00.000Z',
});

const makeClutchTurnRef = () => ({
  turn_number: 1,
  map_uid: 'uid-alpha',
  map_name: 'Alpha Arena',
  recorded_at: '2026-03-01T10:00:00.000Z',
  defender_logins: ['def1', 'def2', 'def3'],
  alive_defenders_at_end: 1,
  total_defenders: 3,
  outcome: 'time_limit',
});

const makeServiceStub = () => ({
  getEliteTurns: vi.fn().mockResolvedValue({
    server_login: 'test-server',
    turns: [makeEliteTurnSummary()],
    pagination: { total: 1, limit: 50, offset: 0 },
  }),
  getEliteTurnByNumber: vi.fn().mockResolvedValue(makeEliteTurnDetail()),
  getPlayerClutchStats: vi.fn().mockResolvedValue({
    server_login: 'test-server',
    player_login: 'def1',
    clutch_count: 2,
    total_defense_rounds: 5,
    clutch_rate: 0.4,
    clutch_turns: [makeClutchTurnRef()],
  }),
  getElitePlayerTurnHistory: vi.fn().mockResolvedValue({
    server_login: 'test-server',
    player_login: 'def1',
    data: [
      {
        turn_number: 1,
        map_uid: 'uid-alpha',
        map_name: 'Alpha Arena',
        recorded_at: '2026-03-01T10:00:00.000Z',
        role: 'defender',
        stats: { kills: 0, deaths: 1, hits: 0, shots: 1, misses: 1, rocket_hits: 0 },
        outcome: 'time_limit',
        defense_success: true,
        clutch: makeClutch(),
      },
    ],
    pagination: { total: 1, limit: 50, offset: 0 },
  }),
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('EliteStatsReadController', () => {
  let controller: EliteStatsReadController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new EliteStatsReadController(service as never);
  });

  // ---------------------------------------------------------------------------
  // getEliteTurns
  // ---------------------------------------------------------------------------

  describe('getEliteTurns', () => {
    it('calls service.getEliteTurns with correct parameters', async () => {
      const query = { limit: 10, offset: 5, since: '2026-03-01T00:00:00Z', until: '2026-03-02T00:00:00Z' };
      await controller.getEliteTurns('test-server', query as never);
      expect(service.getEliteTurns).toHaveBeenCalledWith('test-server', 10, 5, '2026-03-01T00:00:00Z', '2026-03-02T00:00:00Z');
    });

    it('uses default limit=50 and offset=0 when not provided', async () => {
      await controller.getEliteTurns('test-server', {} as never);
      expect(service.getEliteTurns).toHaveBeenCalledWith('test-server', 50, 0, undefined, undefined);
    });

    it('returns the service response unchanged', async () => {
      const result = await controller.getEliteTurns('test-server', {} as never);
      expect(result.server_login).toBe('test-server');
      expect(result.turns).toHaveLength(1);
      expect(result.turns[0].event_kind).toBe('elite_turn_summary');
    });

    it('propagates NotFoundException from service', async () => {
      service.getEliteTurns.mockRejectedValue(new NotFoundException('Server not found'));
      await expect(controller.getEliteTurns('unknown-server', {} as never)).rejects.toThrow(NotFoundException);
    });
  });

  // ---------------------------------------------------------------------------
  // getEliteTurnByNumber
  // ---------------------------------------------------------------------------

  describe('getEliteTurnByNumber', () => {
    it('calls service.getEliteTurnByNumber with correct parameters', async () => {
      await controller.getEliteTurnByNumber('test-server', 3);
      expect(service.getEliteTurnByNumber).toHaveBeenCalledWith('test-server', 3);
    });

    it('returns the service response with server_login, event_id, recorded_at', async () => {
      const result = await controller.getEliteTurnByNumber('test-server', 1);
      expect(result.turn_number).toBe(1);
      expect(result.server_login).toBe('test-server');
      expect(result.event_id).toBe('pc-evt-elite-1');
      expect(result.recorded_at).toBeDefined();
    });

    it('propagates NotFoundException from service when turn not found', async () => {
      service.getEliteTurnByNumber.mockRejectedValue(new NotFoundException('Turn not found'));
      await expect(controller.getEliteTurnByNumber('test-server', 999)).rejects.toThrow(NotFoundException);
    });
  });

  // ---------------------------------------------------------------------------
  // getPlayerClutchStats
  // ---------------------------------------------------------------------------

  describe('getPlayerClutchStats', () => {
    it('calls service.getPlayerClutchStats with correct parameters', async () => {
      await controller.getPlayerClutchStats('test-server', 'def1');
      expect(service.getPlayerClutchStats).toHaveBeenCalledWith('test-server', 'def1');
    });

    it('returns clutch count, rate, and turns', async () => {
      const result = await controller.getPlayerClutchStats('test-server', 'def1');
      expect(result.clutch_count).toBe(2);
      expect(result.total_defense_rounds).toBe(5);
      expect(result.clutch_rate).toBe(0.4);
      expect(result.clutch_turns).toHaveLength(1);
    });

    it('propagates NotFoundException when server not found', async () => {
      service.getPlayerClutchStats.mockRejectedValue(new NotFoundException('Server not found'));
      await expect(controller.getPlayerClutchStats('unknown', 'def1')).rejects.toThrow(NotFoundException);
    });
  });

  // ---------------------------------------------------------------------------
  // getElitePlayerTurnHistory
  // ---------------------------------------------------------------------------

  describe('getElitePlayerTurnHistory', () => {
    it('calls service.getElitePlayerTurnHistory with correct parameters', async () => {
      const query = { limit: 20, offset: 2 };
      await controller.getElitePlayerTurnHistory('test-server', 'def1', query as never);
      expect(service.getElitePlayerTurnHistory).toHaveBeenCalledWith('test-server', 'def1', 20, 2);
    });

    it('uses default limit=50 and offset=0 when not provided', async () => {
      await controller.getElitePlayerTurnHistory('test-server', 'def1', {} as never);
      expect(service.getElitePlayerTurnHistory).toHaveBeenCalledWith('test-server', 'def1', 50, 0);
    });

    it('returns paginated turn history with role and stats', async () => {
      const result = await controller.getElitePlayerTurnHistory('test-server', 'def1', {} as never);
      expect(result.server_login).toBe('test-server');
      expect(result.player_login).toBe('def1');
      expect(result.data).toHaveLength(1);
      expect(result.data[0].role).toBe('defender');
      expect(result.data[0].stats.deaths).toBe(1);
    });

    it('propagates NotFoundException when server not found', async () => {
      service.getElitePlayerTurnHistory.mockRejectedValue(new NotFoundException('Server not found'));
      await expect(controller.getElitePlayerTurnHistory('unknown', 'def1', {} as never)).rejects.toThrow(NotFoundException);
    });
  });
});
