import { NotFoundException } from '@nestjs/common';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { StatsReadController } from './stats-read.controller';

const makeMapEntry = (uid = 'uid-alpha') => ({
  map_uid: uid,
  map_name: `Map ${uid}`,
  played_at: '2026-02-28T10:00:00.000Z',
  duration_seconds: 120,
  player_stats: { player1: { kills: 5, deaths: 2, hits: 20, shots: 40, misses: 20, rockets: 10, lasers: 10, accuracy: 0.5 } },
  team_stats: [],
  totals: { kills: 5 },
  win_context: {},
  event_id: 'pc-evt-lc-1',
});

const makeServiceStub = () => ({
  getCombatStats: vi.fn().mockResolvedValue({
    server_login: 'test-server',
    combat_summary: { total_events: 0 },
    time_range: { since: null, until: null, event_count: 0 },
  }),
  getCombatPlayersCounters: vi.fn().mockResolvedValue({
    data: [],
    pagination: { total: 0, limit: 50, offset: 0 },
  }),
  getPlayerCombatCounters: vi.fn().mockResolvedValue({
    login: 'player1',
    counters: { kills: 10 },
    recent_events_count: 5,
    last_updated: '2026-02-28T10:00:00.000Z',
  }),
  getLatestScores: vi.fn().mockResolvedValue({
    server_login: 'test-server',
    scores_section: 'EndRound',
    scores_snapshot: null,
    scores_result: null,
    source_time: null,
    event_id: null,
  }),
  getMapCombatStatsList: vi.fn().mockResolvedValue({
    server_login: 'test-server',
    maps: [makeMapEntry()],
    pagination: { total: 1, limit: 50, offset: 0 },
  }),
  getMapCombatStats: vi.fn().mockResolvedValue(makeMapEntry()),
  getMapPlayerCombatStats: vi.fn().mockResolvedValue({
    server_login: 'test-server',
    map_uid: 'uid-alpha',
    map_name: 'Map uid-alpha',
    player_login: 'player1',
    counters: { kills: 5, deaths: 2, hits: 20, shots: 40, misses: 20, rockets: 10, lasers: 10, accuracy: 0.5 },
    played_at: '2026-02-28T10:00:00.000Z',
  }),
  getSeriesCombatStatsList: vi.fn().mockResolvedValue({
    server_login: 'test-server',
    series: [],
    pagination: { total: 0, limit: 50, offset: 0 },
  }),
});

describe('StatsReadController', () => {
  let controller: StatsReadController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new StatsReadController(service as never);
  });

  describe('getCombatStats', () => {
    it('calls service with no time range by default', async () => {
      await controller.getCombatStats('test-server', {});

      expect(service.getCombatStats).toHaveBeenCalledWith('test-server', undefined, undefined);
    });

    it('passes since/until to service', async () => {
      await controller.getCombatStats('test-server', {
        since: '2026-02-28T09:00:00Z',
        until: '2026-02-28T10:00:00Z',
      });

      expect(service.getCombatStats).toHaveBeenCalledWith(
        'test-server',
        '2026-02-28T09:00:00Z',
        '2026-02-28T10:00:00Z',
      );
    });
  });

  describe('getCombatPlayers', () => {
    it('calls service with default pagination', async () => {
      await controller.getCombatPlayers('test-server', {});

      expect(service.getCombatPlayersCounters).toHaveBeenCalledWith(
        'test-server',
        50,
        0,
        undefined,
        undefined,
      );
    });
  });

  describe('getPlayerCombatCounters', () => {
    it('delegates to service and returns counters', async () => {
      const result = await controller.getPlayerCombatCounters('test-server', 'player1');

      expect(service.getPlayerCombatCounters).toHaveBeenCalledWith('test-server', 'player1');
      expect(result.login).toBe('player1');
    });

    it('re-throws NotFoundException from service', async () => {
      service.getPlayerCombatCounters.mockRejectedValue(
        new NotFoundException('No combat data found'),
      );

      await expect(
        controller.getPlayerCombatCounters('test-server', 'unknown'),
      ).rejects.toThrow(NotFoundException);
    });
  });

  describe('getLatestScores', () => {
    it('delegates to service', async () => {
      const result = await controller.getLatestScores('test-server');

      expect(service.getLatestScores).toHaveBeenCalledWith('test-server');
      expect(result.server_login).toBe('test-server');
    });
  });

  // -------------------------------------------------------------------------
  // P2.5 per-map / per-series combat stats controller tests
  // -------------------------------------------------------------------------

  describe('getCombatMaps', () => {
    it('calls service with default pagination', async () => {
      const result = await controller.getCombatMaps('test-server', {});

      expect(service.getMapCombatStatsList).toHaveBeenCalledWith(
        'test-server',
        50,
        0,
        undefined,
        undefined,
      );
      expect(result.server_login).toBe('test-server');
    });

    it('passes limit, offset, since, until to service', async () => {
      await controller.getCombatMaps('test-server', {
        limit: 10,
        offset: 5,
        since: '2026-02-28T09:00:00Z',
        until: '2026-02-28T10:00:00Z',
      });

      expect(service.getMapCombatStatsList).toHaveBeenCalledWith(
        'test-server',
        10,
        5,
        '2026-02-28T09:00:00Z',
        '2026-02-28T10:00:00Z',
      );
    });
  });

  describe('getCombatMapByUid', () => {
    it('delegates to service with serverLogin and mapUid', async () => {
      const result = await controller.getCombatMapByUid('test-server', 'uid-alpha');

      expect(service.getMapCombatStats).toHaveBeenCalledWith('test-server', 'uid-alpha');
      expect(result.map_uid).toBe('uid-alpha');
    });

    it('re-throws NotFoundException from service', async () => {
      service.getMapCombatStats.mockRejectedValue(
        new NotFoundException('No combat stats found for map'),
      );

      await expect(
        controller.getCombatMapByUid('test-server', 'uid-missing'),
      ).rejects.toThrow(NotFoundException);
    });
  });

  describe('getCombatMapPlayer', () => {
    it('delegates to service with all three params', async () => {
      const result = await controller.getCombatMapPlayer('test-server', 'uid-alpha', 'player1');

      expect(service.getMapPlayerCombatStats).toHaveBeenCalledWith(
        'test-server',
        'uid-alpha',
        'player1',
      );
      expect(result.player_login).toBe('player1');
    });

    it('re-throws NotFoundException when player not found', async () => {
      service.getMapPlayerCombatStats.mockRejectedValue(
        new NotFoundException('No stats found for player'),
      );

      await expect(
        controller.getCombatMapPlayer('test-server', 'uid-alpha', 'unknown'),
      ).rejects.toThrow(NotFoundException);
    });

    it('re-throws NotFoundException when map not found', async () => {
      service.getMapPlayerCombatStats.mockRejectedValue(
        new NotFoundException('No combat stats found for map'),
      );

      await expect(
        controller.getCombatMapPlayer('test-server', 'uid-missing', 'player1'),
      ).rejects.toThrow(NotFoundException);
    });
  });

  describe('getCombatSeries', () => {
    it('calls service with default pagination', async () => {
      const result = await controller.getCombatSeries('test-server', {});

      expect(service.getSeriesCombatStatsList).toHaveBeenCalledWith(
        'test-server',
        50,
        0,
        undefined,
        undefined,
      );
      expect(result.server_login).toBe('test-server');
    });

    it('passes limit, offset, since, until to service', async () => {
      await controller.getCombatSeries('test-server', {
        limit: 5,
        offset: 2,
        since: '2026-02-28T09:00:00Z',
        until: '2026-02-28T10:00:00Z',
      });

      expect(service.getSeriesCombatStatsList).toHaveBeenCalledWith(
        'test-server',
        5,
        2,
        '2026-02-28T09:00:00Z',
        '2026-02-28T10:00:00Z',
      );
    });
  });
});
