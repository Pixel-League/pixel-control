import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ModeReadService } from './mode-read.service';

const makeServer = (overrides = {}) => ({
  id: 'server-uuid',
  serverLogin: 'test-server',
  gameMode: 'Elite',
  titleId: 'SMStormElite@nadeolabs',
  ...overrides,
});

const makeModeEvent = (overrides: {
  eventName?: string;
  eventId?: string;
  sourceCallback?: string;
  sourceTime?: bigint;
  rawCallbackSummary?: Record<string, unknown>;
}) => ({
  id: 'evt-mode-1',
  eventId: overrides.eventId ?? 'pc-evt-mode-1',
  eventName: overrides.eventName ?? 'pixel_control.mode.elite_startturn',
  eventCategory: 'mode',
  sourceCallback: overrides.sourceCallback ?? 'SM_ELITE_STARTTURN',
  sourceTime: overrides.sourceTime ?? BigInt(1000000),
  idempotencyKey: 'idem-mode-1',
  schemaVersion: '2026-02-20.1',
  receivedAt: new Date('2026-02-28T10:00:00Z'),
  metadata: null,
  payload: {
    raw_callback_summary: overrides.rawCallbackSummary ?? { turn: 1, attacker: 'player1' },
  },
});

const makeResolverStub = (server = makeServer()) => ({
  resolve: vi.fn().mockResolvedValue({ server, online: true }),
});

const makePrismaStub = () => ({
  event: {
    findMany: vi.fn().mockResolvedValue([]),
    count: vi.fn().mockResolvedValue(0),
  },
});

describe('ModeReadService', () => {
  let service: ModeReadService;
  let resolver: ReturnType<typeof makeResolverStub>;
  let prisma: ReturnType<typeof makePrismaStub>;

  beforeEach(() => {
    resolver = makeResolverStub();
    prisma = makePrismaStub();
    service = new ModeReadService(resolver as never, prisma as never);
  });

  describe('getModeData', () => {
    it('returns empty mode data when no mode events', async () => {
      prisma.event.findMany.mockResolvedValue([]);
      prisma.event.count.mockResolvedValue(0);

      const result = await service.getModeData('test-server', 10);

      expect(result.recent_mode_events).toHaveLength(0);
      expect(result.total_mode_events).toBe(0);
      expect(result.last_updated).toBeNull();
    });

    it('returns game_mode and title_id from server record', async () => {
      prisma.event.findMany.mockResolvedValue([]);
      prisma.event.count.mockResolvedValue(0);

      const result = await service.getModeData('test-server', 10);

      expect(result.game_mode).toBe('Elite');
      expect(result.title_id).toBe('SMStormElite@nadeolabs');
    });

    it('returns recent mode events', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeModeEvent({
          sourceCallback: 'SM_ELITE_STARTTURN',
          rawCallbackSummary: { turn: 1 },
        }),
      ]);
      prisma.event.count.mockResolvedValue(28);

      const result = await service.getModeData('test-server', 10);

      expect(result.recent_mode_events).toHaveLength(1);
      expect(result.recent_mode_events[0]!.source_callback).toBe('SM_ELITE_STARTTURN');
      expect(result.recent_mode_events[0]!.raw_callback_summary).toEqual({ turn: 1 });
      expect(result.total_mode_events).toBe(28);
    });

    it('respects limit parameter', async () => {
      prisma.event.findMany.mockResolvedValue([makeModeEvent({})]);
      prisma.event.count.mockResolvedValue(1);

      await service.getModeData('test-server', 5);

      expect(prisma.event.findMany).toHaveBeenCalledWith(
        expect.objectContaining({ take: 5 }),
      );
    });

    it('sets last_updated from the most recent event sourceTime', async () => {
      prisma.event.findMany.mockResolvedValue([
        makeModeEvent({ sourceTime: BigInt(2000000) }),
      ]);
      prisma.event.count.mockResolvedValue(1);

      const result = await service.getModeData('test-server', 10);

      expect(result.last_updated).toBe(new Date(Number(BigInt(2000000))).toISOString());
    });
  });
});
