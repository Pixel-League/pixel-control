import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ModeReadController } from './mode-read.controller';

const makeServiceStub = () => ({
  getModeData: vi.fn().mockResolvedValue({
    server_login: 'test-server',
    game_mode: 'Elite',
    title_id: 'SMStormElite@nadeolabs',
    recent_mode_events: [],
    total_mode_events: 0,
    last_updated: null,
  }),
});

describe('ModeReadController', () => {
  let controller: ModeReadController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new ModeReadController(service as never);
  });

  describe('getModeData', () => {
    it('delegates to service with default limit', async () => {
      await controller.getModeData('test-server', {});

      expect(service.getModeData).toHaveBeenCalledWith('test-server', 10);
    });

    it('passes custom limit to service', async () => {
      await controller.getModeData('test-server', { limit: 25 });

      expect(service.getModeData).toHaveBeenCalledWith('test-server', 25);
    });

    it('returns mode data from service', async () => {
      service.getModeData.mockResolvedValue({
        server_login: 'test-server',
        game_mode: 'Joust',
        title_id: 'SMStormJoust@nadeolabs',
        recent_mode_events: [{ event_name: 'pixel_control.mode.joust_newturn' }],
        total_mode_events: 15,
        last_updated: '2026-02-28T10:00:00.000Z',
      });

      const result = await controller.getModeData('test-server', {});

      expect(result.game_mode).toBe('Joust');
      expect(result.total_mode_events).toBe(15);
      expect(result.recent_mode_events).toHaveLength(1);
    });
  });
});
