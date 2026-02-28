import { NotFoundException } from '@nestjs/common';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PlayersReadController } from './players-read.controller';

const makeServiceStub = () => ({
  getPlayers: vi.fn().mockResolvedValue({
    data: [],
    pagination: { total: 0, limit: 50, offset: 0 },
  }),
  getPlayer: vi.fn().mockResolvedValue({
    login: 'player1',
    nickname: 'Player One',
    is_connected: true,
    last_event_id: 'evt-123',
    last_updated: '2026-02-28T10:00:00.000Z',
  }),
});

describe('PlayersReadController', () => {
  let controller: PlayersReadController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new PlayersReadController(service as never);
  });

  describe('getPlayers', () => {
    it('delegates to service with default pagination', async () => {
      await controller.getPlayers('test-server', {});

      expect(service.getPlayers).toHaveBeenCalledWith('test-server', 50, 0);
    });

    it('passes custom limit and offset to service', async () => {
      await controller.getPlayers('test-server', { limit: 10, offset: 5 });

      expect(service.getPlayers).toHaveBeenCalledWith('test-server', 10, 5);
    });

    it('returns paginated response from service', async () => {
      service.getPlayers.mockResolvedValue({
        data: [{ login: 'p1' }],
        pagination: { total: 1, limit: 50, offset: 0 },
      });

      const result = await controller.getPlayers('test-server', {});

      expect(result.data).toHaveLength(1);
      expect(result.pagination.total).toBe(1);
    });
  });

  describe('getPlayer', () => {
    it('delegates to service and returns player state', async () => {
      const result = await controller.getPlayer('test-server', 'player1');

      expect(service.getPlayer).toHaveBeenCalledWith('test-server', 'player1');
      expect(result.login).toBe('player1');
    });

    it('re-throws NotFoundException from service', async () => {
      service.getPlayer.mockRejectedValue(
        new NotFoundException("Player 'x' not found on server 'test-server'"),
      );

      await expect(
        controller.getPlayer('test-server', 'x'),
      ).rejects.toThrow(NotFoundException);
    });
  });
});
