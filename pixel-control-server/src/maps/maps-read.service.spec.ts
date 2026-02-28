import { beforeEach, describe, expect, it, vi } from 'vitest';

import { MapsReadService } from './maps-read.service';

const makeServer = (overrides = {}) => ({
  id: 'server-uuid',
  serverLogin: 'test-server',
  ...overrides,
});

const makeLifecycleEventWithMapPool = (mapPool: unknown[]) => ({
  id: 'evt-lc-1',
  eventId: 'pc-evt-lifecycle-1',
  eventName: 'pixel_control.lifecycle.map_begin',
  eventCategory: 'lifecycle',
  sourceCallback: 'SM_BEGIN_MAP',
  sourceTime: BigInt(1000000),
  idempotencyKey: 'idem-lc-1',
  schemaVersion: '2026-02-20.1',
  receivedAt: new Date('2026-02-28T10:00:00Z'),
  metadata: null,
  payload: {
    variant: 'map.begin',
    map_rotation: {
      map_pool: mapPool,
      current_map: mapPool[0] ?? null,
      current_map_index: 0,
    },
  },
});

const makeResolverStub = (server = makeServer()) => ({
  resolve: vi.fn().mockResolvedValue({ server, online: true }),
});

const makePrismaStub = () => ({
  event: {
    findMany: vi.fn().mockResolvedValue([]),
  },
});

describe('MapsReadService', () => {
  let service: MapsReadService;
  let resolver: ReturnType<typeof makeResolverStub>;
  let prisma: ReturnType<typeof makePrismaStub>;

  beforeEach(() => {
    resolver = makeResolverStub();
    prisma = makePrismaStub();
    service = new MapsReadService(resolver as never, prisma as never);
  });

  describe('getMaps', () => {
    it('returns empty list when no lifecycle events with map data', async () => {
      prisma.event.findMany.mockResolvedValue([]);

      const result = await service.getMaps('test-server');

      expect(result.maps).toHaveLength(0);
      expect(result.map_count).toBe(0);
      expect(result.current_map).toBeNull();
      expect(result.last_updated).toBeNull();
    });

    it('returns map pool from latest event with map_rotation', async () => {
      const mapPool = [
        { uid: 'uid1', name: 'Forest', file: 'Forest.Gbx' },
        { uid: 'uid2', name: 'Desert', file: 'Desert.Gbx' },
      ];
      prisma.event.findMany.mockResolvedValue([makeLifecycleEventWithMapPool(mapPool)]);

      const result = await service.getMaps('test-server');

      expect(result.maps).toHaveLength(2);
      expect(result.map_count).toBe(2);
      expect(result.current_map).toEqual({ uid: 'uid1', name: 'Forest', file: 'Forest.Gbx' });
      expect(result.current_map_index).toBe(0);
    });

    it('returns last_updated from event sourceTime', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeLifecycleEventWithMapPool([{ uid: 'uid1', name: 'Map' }]),
      ]);

      const result = await service.getMaps('test-server');

      expect(result.last_updated).toBe(new Date(Number(BigInt(1000000))).toISOString());
    });

    it('ignores lifecycle events without map_rotation', async () => {
      prisma.event.findMany.mockResolvedValue([
        {
          id: 'evt-1',
          payload: { variant: 'round.begin' },
          sourceTime: BigInt(1000000),
          eventId: 'pc-evt-1',
        },
      ]);

      const result = await service.getMaps('test-server');

      expect(result.maps).toHaveLength(0);
    });
  });
});
