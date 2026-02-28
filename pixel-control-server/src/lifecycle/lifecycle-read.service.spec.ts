import { beforeEach, describe, expect, it, vi } from 'vitest';

import { LifecycleReadService } from './lifecycle-read.service';

const makeServer = (overrides = {}) => ({
  id: 'server-uuid',
  serverLogin: 'test-server',
  ...overrides,
});

const makeLifecycleEvent = (overrides: {
  variant?: string;
  sourceTime?: bigint;
  mapRotation?: Record<string, unknown>;
  aggregateStats?: Record<string, unknown>;
}) => ({
  id: 'evt-lc-1',
  eventId: 'pc-evt-lifecycle-1',
  eventName: 'pixel_control.lifecycle.event',
  eventCategory: 'lifecycle',
  sourceCallback: 'SM_BEGIN_ROUND',
  sourceTime: overrides.sourceTime ?? BigInt(1000000),
  idempotencyKey: 'idem-lc-1',
  schemaVersion: '2026-02-20.1',
  receivedAt: new Date('2026-02-28T10:00:00Z'),
  metadata: null,
  payload: {
    variant: overrides.variant ?? 'round.begin',
    ...(overrides.mapRotation ? { map_rotation: overrides.mapRotation } : {}),
    ...(overrides.aggregateStats ? { aggregate_stats: overrides.aggregateStats } : {}),
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

describe('LifecycleReadService', () => {
  let service: LifecycleReadService;
  let resolver: ReturnType<typeof makeResolverStub>;
  let prisma: ReturnType<typeof makePrismaStub>;

  beforeEach(() => {
    resolver = makeResolverStub();
    prisma = makePrismaStub();
    service = new LifecycleReadService(resolver as never, prisma as never);
  });

  describe('getLifecycleState', () => {
    it('returns null state when no lifecycle events', async () => {
      prisma.event.findMany.mockResolvedValue([]);

      const result = await service.getLifecycleState('test-server');

      expect(result.current_phase).toBeNull();
      expect(result.match).toBeNull();
      expect(result.map).toBeNull();
      expect(result.round).toBeNull();
      expect(result.warmup.active).toBe(false);
      expect(result.pause.active).toBe(false);
    });

    it('extracts match, map, and round states from latest events', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeLifecycleEvent({ variant: 'round.begin', sourceTime: BigInt(3000000) }),
        makeLifecycleEvent({ variant: 'map.begin', sourceTime: BigInt(2000000) }),
        makeLifecycleEvent({ variant: 'match.begin', sourceTime: BigInt(1000000) }),
      ]);

      const result = await service.getLifecycleState('test-server');

      expect(result.current_phase).toBe('round');
      expect(result.round!.state).toBe('begin');
      expect(result.map!.state).toBe('begin');
      expect(result.match!.state).toBe('begin');
    });

    it('sets warmup active when latest warmup event is start', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeLifecycleEvent({ variant: 'warmup.start', sourceTime: BigInt(2000000) }),
        makeLifecycleEvent({ variant: 'round.begin', sourceTime: BigInt(1000000) }),
      ]);

      const result = await service.getLifecycleState('test-server');

      expect(result.warmup.active).toBe(true);
      expect(result.warmup.last_variant).toBe('warmup.start');
    });

    it('sets warmup inactive when latest warmup event is end', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeLifecycleEvent({ variant: 'warmup.end', sourceTime: BigInt(2000000) }),
        makeLifecycleEvent({ variant: 'warmup.start', sourceTime: BigInt(1000000) }),
      ]);

      const result = await service.getLifecycleState('test-server');

      expect(result.warmup.active).toBe(false);
    });

    it('derives current_phase from most recent lifecycle event', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeLifecycleEvent({ variant: 'map.end', sourceTime: BigInt(2000000) }),
        makeLifecycleEvent({ variant: 'round.end', sourceTime: BigInt(1000000) }),
      ]);

      const result = await service.getLifecycleState('test-server');

      expect(result.current_phase).toBe('map');
    });
  });

  describe('getMapRotation', () => {
    it('returns no_rotation_data when no events with map_rotation', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeLifecycleEvent({ variant: 'round.begin' }),
      ]);

      const result = await service.getMapRotation('test-server');

      expect(result.no_rotation_data).toBe(true);
      expect(result.maps_pool).toBeUndefined();
      expect(result.map_pool).toHaveLength(0);
    });

    it('returns map rotation data from latest event with map_rotation', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeLifecycleEvent({
          variant: 'map.begin',
          sourceTime: BigInt(1000000),
          mapRotation: {
            map_pool: [{ uid: 'uid1', name: 'Map One' }],
            map_pool_size: 1,
            current_map: { uid: 'uid1', name: 'Map One' },
            current_map_index: 0,
            next_maps: [],
            played_map_order: [],
            played_map_count: 0,
            series_targets: { best_of: 3 },
          },
        }),
      ]);

      const result = await service.getMapRotation('test-server');

      expect(result.map_pool).toHaveLength(1);
      expect(result.map_pool_size).toBe(1);
      expect(result.current_map).toEqual({ uid: 'uid1', name: 'Map One' });
    });
  });

  describe('getAggregateStats', () => {
    it('returns empty aggregates when no events with aggregate_stats', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeLifecycleEvent({ variant: 'round.begin' }),
      ]);

      const result = await service.getAggregateStats('test-server');

      expect(result.aggregates).toHaveLength(0);
    });

    it('returns latest aggregate per scope', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeLifecycleEvent({
          variant: 'round.end',
          sourceTime: BigInt(2000000),
          aggregateStats: { scope: 'round', tracked_player_count: 4 },
        }),
        makeLifecycleEvent({
          variant: 'map.end',
          sourceTime: BigInt(1000000),
          aggregateStats: { scope: 'map', tracked_player_count: 6 },
        }),
      ]);

      const result = await service.getAggregateStats('test-server');

      expect(result.aggregates).toHaveLength(2);
      const roundAgg = result.aggregates.find((a) => a.scope === 'round');
      expect(roundAgg!.tracked_player_count).toBe(4);
    });

    it('filters by scope when provided', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeLifecycleEvent({
          variant: 'round.end',
          aggregateStats: { scope: 'round', tracked_player_count: 4 },
        }),
        makeLifecycleEvent({
          variant: 'map.end',
          aggregateStats: { scope: 'map', tracked_player_count: 6 },
        }),
      ]);

      const result = await service.getAggregateStats('test-server', 'round');

      expect(result.aggregates).toHaveLength(1);
      expect(result.aggregates[0]!.scope).toBe('round');
    });
  });
});
