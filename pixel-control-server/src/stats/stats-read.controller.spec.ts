import { NotFoundException } from '@nestjs/common';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { StatsReadController } from './stats-read.controller';

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
});
