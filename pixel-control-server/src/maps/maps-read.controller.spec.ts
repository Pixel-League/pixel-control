import { beforeEach, describe, expect, it, vi } from 'vitest';

import { MapsReadController } from './maps-read.controller';

const makeServiceStub = () => ({
  getMaps: vi.fn().mockResolvedValue({
    server_login: 'test-server',
    maps: [],
    map_count: 0,
    current_map: null,
    current_map_index: null,
    last_updated: null,
  }),
});

describe('MapsReadController', () => {
  let controller: MapsReadController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new MapsReadController(service as never);
  });

  describe('getMaps', () => {
    it('delegates to service', async () => {
      await controller.getMaps('test-server');

      expect(service.getMaps).toHaveBeenCalledWith('test-server');
    });

    it('returns map response from service', async () => {
      service.getMaps.mockResolvedValue({
        server_login: 'test-server',
        maps: [{ uid: 'uid1', name: 'Map One' }],
        map_count: 1,
        current_map: { uid: 'uid1' },
        current_map_index: 0,
        last_updated: '2026-02-28T10:00:00.000Z',
      });

      const result = await controller.getMaps('test-server');

      expect(result.map_count).toBe(1);
      expect(result.maps[0]!.name).toBe('Map One');
    });
  });
});
