import { beforeEach, describe, expect, it, vi } from 'vitest';

import { AdminMapsController } from './admin-maps.controller';

// ---------------------------------------------------------------------------
// Factory helpers
// ---------------------------------------------------------------------------

const makeActionResponse = (overrides: Partial<{ action_name: string; code: string }> = {}) => ({
  action_name: overrides.action_name ?? 'map.skip',
  success: true,
  code: overrides.code ?? 'map_skipped',
  message: 'OK',
  details: undefined,
});

const makeServiceStub = () => ({
  skipMap: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'map.skip', code: 'map_skipped' })),
  restartMap: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'map.restart', code: 'map_restarted' })),
  jumpToMap: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'map.jump', code: 'map_jumped' })),
  queueMap: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'map.queue', code: 'map_queued' })),
  addMap: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'map.add', code: 'map_added' })),
  removeMap: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'map.remove', code: 'map_removed' })),
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('AdminMapsController', () => {
  let controller: AdminMapsController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new AdminMapsController(service as never);
  });

  describe('skipMap', () => {
    it('calls service.skipMap with the serverLogin', async () => {
      await controller.skipMap('test-server');
      expect(service.skipMap).toHaveBeenCalledWith('test-server');
    });

    it('returns the service response', async () => {
      const result = await controller.skipMap('test-server');
      expect(result.action_name).toBe('map.skip');
      expect(result.success).toBe(true);
    });
  });

  describe('restartMap', () => {
    it('calls service.restartMap with the serverLogin', async () => {
      await controller.restartMap('test-server');
      expect(service.restartMap).toHaveBeenCalledWith('test-server');
    });

    it('returns the service response', async () => {
      const result = await controller.restartMap('test-server');
      expect(result.action_name).toBe('map.restart');
    });
  });

  describe('jumpToMap', () => {
    it('calls service.jumpToMap with serverLogin and map_uid', async () => {
      await controller.jumpToMap('test-server', { map_uid: 'uid-abc' });
      expect(service.jumpToMap).toHaveBeenCalledWith('test-server', 'uid-abc');
    });
  });

  describe('queueMap', () => {
    it('calls service.queueMap with serverLogin and map_uid', async () => {
      await controller.queueMap('test-server', { map_uid: 'uid-def' });
      expect(service.queueMap).toHaveBeenCalledWith('test-server', 'uid-def');
    });
  });

  describe('addMap', () => {
    it('calls service.addMap with serverLogin and mx_id', async () => {
      await controller.addMap('test-server', { mx_id: '12345' });
      expect(service.addMap).toHaveBeenCalledWith('test-server', '12345');
    });
  });

  describe('removeMap', () => {
    it('calls service.removeMap with serverLogin and mapUid', async () => {
      await controller.removeMap('test-server', 'uid-ghi');
      expect(service.removeMap).toHaveBeenCalledWith('test-server', 'uid-ghi');
    });

    it('returns the service response', async () => {
      const result = await controller.removeMap('test-server', 'uid-ghi');
      expect(result.action_name).toBe('map.remove');
      expect(result.code).toBe('map_removed');
    });
  });
});
