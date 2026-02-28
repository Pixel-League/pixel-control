import { beforeEach, describe, expect, it, vi } from 'vitest';

import { LifecycleReadController } from './lifecycle-read.controller';

const makeServiceStub = () => ({
  getLifecycleState: vi.fn().mockResolvedValue({
    server_login: 'test-server',
    current_phase: null,
    match: null,
    map: null,
    round: null,
    warmup: { active: false, last_variant: null, source_time: null },
    pause: { active: false, last_variant: null, source_time: null },
    last_updated: null,
  }),
  getMapRotation: vi.fn().mockResolvedValue({
    server_login: 'test-server',
    map_pool: [],
    no_rotation_data: true,
  }),
  getAggregateStats: vi.fn().mockResolvedValue({
    server_login: 'test-server',
    aggregates: [],
  }),
});

describe('LifecycleReadController', () => {
  let controller: LifecycleReadController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new LifecycleReadController(service as never);
  });

  describe('getLifecycleState', () => {
    it('delegates to service', async () => {
      await controller.getLifecycleState('test-server');

      expect(service.getLifecycleState).toHaveBeenCalledWith('test-server');
    });

    it('returns lifecycle state from service', async () => {
      const result = await controller.getLifecycleState('test-server');

      expect(result.server_login).toBe('test-server');
      expect(result.warmup.active).toBe(false);
    });
  });

  describe('getMapRotation', () => {
    it('delegates to service', async () => {
      await controller.getMapRotation('test-server');

      expect(service.getMapRotation).toHaveBeenCalledWith('test-server');
    });
  });

  describe('getAggregateStats', () => {
    it('delegates to service without scope', async () => {
      await controller.getAggregateStats('test-server');

      expect(service.getAggregateStats).toHaveBeenCalledWith('test-server', undefined);
    });

    it('passes scope filter to service', async () => {
      await controller.getAggregateStats('test-server', 'round');

      expect(service.getAggregateStats).toHaveBeenCalledWith('test-server', 'round');
    });
  });
});
