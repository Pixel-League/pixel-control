import { beforeEach, describe, expect, it, vi } from 'vitest';

import { EventEnvelopeDto } from '../common/dto/event-envelope.dto';
import { ConnectivityService } from '../connectivity/connectivity.service';
import { IngestionService } from './ingestion.service';
import { BatchService } from './services/batch.service';
import { CombatService } from './services/combat.service';
import { LifecycleService } from './services/lifecycle.service';
import { ModeService } from './services/mode.service';
import { PlayerService } from './services/player.service';

const makeEnvelope = (
  overrides: Partial<EventEnvelopeDto> = {},
): EventEnvelopeDto => ({
  event_name: 'pixel_control.connectivity.plugin_registration',
  schema_version: '2026-02-20.1',
  event_id: 'pc-evt-connectivity-plugin_registration-1',
  event_category: 'connectivity',
  source_callback: 'PixelControl.PluginRegistration',
  source_sequence: 1,
  source_time: 1740000000,
  idempotency_key: 'pc-idem-test-001',
  payload: { type: 'plugin_registration' },
  ...overrides,
});

const makePrismaStub = () => ({
  event: {
    findUnique: vi.fn().mockResolvedValue(null),
    create: vi.fn().mockResolvedValue({ id: 'evt-uuid' }),
    groupBy: vi.fn().mockResolvedValue([]),
  },
  server: {
    findUnique: vi.fn(),
    create: vi.fn(),
    update: vi.fn().mockResolvedValue({}),
  },
});

const makeConnectivityServiceStub = () => ({
  ingestConnectivityEvent: vi.fn().mockResolvedValue(undefined),
});

const makeLifecycleServiceStub = () => ({
  ingestLifecycleEvent: vi.fn().mockResolvedValue(undefined),
});

const makeCombatServiceStub = () => ({
  ingestCombatEvent: vi.fn().mockResolvedValue(undefined),
});

const makePlayerServiceStub = () => ({
  ingestPlayerEvent: vi.fn().mockResolvedValue(undefined),
});

const makeModeServiceStub = () => ({
  ingestModeEvent: vi.fn().mockResolvedValue(undefined),
});

const makeBatchServiceStub = () => ({
  ingestBatchEvent: vi.fn().mockResolvedValue({
    ack: {
      status: 'accepted',
      batch_size: 0,
      accepted: 0,
      duplicates: 0,
      rejected: 0,
    },
  }),
});

describe('IngestionService', () => {
  let service: IngestionService;
  let prisma: ReturnType<typeof makePrismaStub>;
  let connectivityService: ReturnType<typeof makeConnectivityServiceStub>;
  let lifecycleService: ReturnType<typeof makeLifecycleServiceStub>;
  let combatService: ReturnType<typeof makeCombatServiceStub>;
  let playerService: ReturnType<typeof makePlayerServiceStub>;
  let modeService: ReturnType<typeof makeModeServiceStub>;
  let batchService: ReturnType<typeof makeBatchServiceStub>;

  beforeEach(() => {
    prisma = makePrismaStub();
    connectivityService = makeConnectivityServiceStub();
    lifecycleService = makeLifecycleServiceStub();
    combatService = makeCombatServiceStub();
    playerService = makePlayerServiceStub();
    modeService = makeModeServiceStub();
    batchService = makeBatchServiceStub();

    service = new IngestionService(
      prisma as never,
      connectivityService as unknown as ConnectivityService,
      lifecycleService as unknown as LifecycleService,
      combatService as unknown as CombatService,
      playerService as unknown as PlayerService,
      modeService as unknown as ModeService,
      batchService as unknown as BatchService,
    );
  });

  describe('Unified Event table storage', () => {
    it('stores event in unified Event table and returns accepted ack', async () => {
      prisma.server.findUnique.mockResolvedValue({
        id: 'server-uuid',
        serverLogin: 'test-server',
      });

      const result = await service.ingestEvent(
        'test-server',
        '1.0.0',
        makeEnvelope(),
      );

      expect(result).toEqual({ ack: { status: 'accepted' } });
      expect(prisma.event.create).toHaveBeenCalledOnce();
      expect(prisma.event.create).toHaveBeenCalledWith(
        expect.objectContaining({
          data: expect.objectContaining({
            serverId: 'server-uuid',
            eventName: 'pixel_control.connectivity.plugin_registration',
            eventCategory: 'connectivity',
            idempotencyKey: 'pc-idem-test-001',
          }),
        }),
      );
    });

    it('stores BigInt fields for sourceSequence and sourceTime', async () => {
      prisma.server.findUnique.mockResolvedValue({
        id: 'server-uuid',
        serverLogin: 'test-server',
      });

      await service.ingestEvent(
        'test-server',
        '1.0.0',
        makeEnvelope({ source_sequence: 1740000000123, source_time: 1740000000456 }),
      );

      expect(prisma.event.create).toHaveBeenCalledWith(
        expect.objectContaining({
          data: expect.objectContaining({
            sourceSequence: BigInt(1740000000123),
            sourceTime: BigInt(1740000000456),
          }),
        }),
      );
    });
  });

  describe('Idempotency', () => {
    it('returns duplicate ack for known idempotency_key', async () => {
      prisma.event.findUnique.mockResolvedValue({
        id: 'existing-evt',
        idempotencyKey: 'pc-idem-test-001',
      });

      const result = await service.ingestEvent(
        'test-server',
        undefined,
        makeEnvelope(),
      );

      expect(result).toEqual({
        ack: { status: 'accepted', disposition: 'duplicate' },
      });
      expect(prisma.event.create).not.toHaveBeenCalled();
    });
  });

  describe('Auto-registration', () => {
    it('auto-registers unknown server on first event', async () => {
      prisma.server.findUnique.mockResolvedValue(null);
      prisma.server.create.mockResolvedValue({
        id: 'new-server-uuid',
        serverLogin: 'unknown-server',
      });

      const result = await service.ingestEvent(
        'unknown-server',
        undefined,
        makeEnvelope(),
      );

      expect(prisma.server.create).toHaveBeenCalledWith(
        expect.objectContaining({
          data: expect.objectContaining({
            serverLogin: 'unknown-server',
            linked: false,
          }),
        }),
      );
      expect(result).toEqual({ ack: { status: 'accepted' } });
    });
  });

  describe('Category dispatch', () => {
    beforeEach(() => {
      prisma.server.findUnique.mockResolvedValue({
        id: 'server-uuid',
        serverLogin: 'test-server',
      });
    });

    it('dispatches connectivity event to ConnectivityService', async () => {
      await service.ingestEvent(
        'test-server',
        '1.0.0',
        makeEnvelope({ event_category: 'connectivity' }),
      );

      expect(
        connectivityService.ingestConnectivityEvent,
      ).toHaveBeenCalledOnce();
      expect(
        connectivityService.ingestConnectivityEvent,
      ).toHaveBeenCalledWith(
        'server-uuid',
        '1.0.0',
        expect.objectContaining({ event_category: 'connectivity' }),
      );
    });

    it('dispatches lifecycle event to LifecycleService', async () => {
      await service.ingestEvent(
        'test-server',
        undefined,
        makeEnvelope({
          event_name: 'pixel_control.lifecycle.sm_begin_map',
          event_category: 'lifecycle',
          event_id: 'pc-evt-lifecycle-sm_begin_map-2',
          idempotency_key: 'pc-idem-lifecycle-002',
        }),
      );

      expect(lifecycleService.ingestLifecycleEvent).toHaveBeenCalledOnce();
      expect(lifecycleService.ingestLifecycleEvent).toHaveBeenCalledWith(
        'server-uuid',
        expect.objectContaining({ event_category: 'lifecycle' }),
      );
    });

    it('dispatches combat event to CombatService', async () => {
      await service.ingestEvent(
        'test-server',
        undefined,
        makeEnvelope({
          event_name: 'pixel_control.combat.smshoot',
          event_category: 'combat',
          event_id: 'pc-evt-combat-smshoot-3',
          idempotency_key: 'pc-idem-combat-003',
        }),
      );

      expect(combatService.ingestCombatEvent).toHaveBeenCalledOnce();
      expect(combatService.ingestCombatEvent).toHaveBeenCalledWith(
        'server-uuid',
        expect.objectContaining({ event_category: 'combat' }),
      );
    });

    it('dispatches player event to PlayerService', async () => {
      await service.ingestEvent(
        'test-server',
        undefined,
        makeEnvelope({
          event_name: 'pixel_control.player.smplayerconnect',
          event_category: 'player',
          event_id: 'pc-evt-player-smplayerconnect-4',
          idempotency_key: 'pc-idem-player-004',
        }),
      );

      expect(playerService.ingestPlayerEvent).toHaveBeenCalledOnce();
      expect(playerService.ingestPlayerEvent).toHaveBeenCalledWith(
        'server-uuid',
        expect.objectContaining({ event_category: 'player' }),
      );
    });

    it('dispatches mode event to ModeService', async () => {
      await service.ingestEvent(
        'test-server',
        undefined,
        makeEnvelope({
          event_name: 'pixel_control.mode.smmodescriptelitestartturn',
          event_category: 'mode',
          event_id: 'pc-evt-mode-smmodescriptelitestartturn-5',
          idempotency_key: 'pc-idem-mode-005',
        }),
      );

      expect(modeService.ingestModeEvent).toHaveBeenCalledOnce();
      expect(modeService.ingestModeEvent).toHaveBeenCalledWith(
        'server-uuid',
        expect.objectContaining({ event_category: 'mode' }),
      );
    });

    it('handles unknown category gracefully — stores event, no category dispatch, returns accepted', async () => {
      const result = await service.ingestEvent(
        'test-server',
        undefined,
        makeEnvelope({
          event_name: 'pixel_control.unknown.some_event',
          event_category: 'unknown_category',
          idempotency_key: 'pc-idem-unknown-099',
        }),
      );

      expect(result).toEqual({ ack: { status: 'accepted' } });
      expect(prisma.event.create).toHaveBeenCalledOnce();
      expect(connectivityService.ingestConnectivityEvent).not.toHaveBeenCalled();
      expect(lifecycleService.ingestLifecycleEvent).not.toHaveBeenCalled();
    });

    it('dispatches batch event to BatchService and returns batch ack', async () => {
      const batchAck = {
        ack: {
          status: 'accepted' as const,
          batch_size: 2,
          accepted: 2,
          duplicates: 0,
          rejected: 0,
        },
      };
      batchService.ingestBatchEvent.mockResolvedValue(batchAck);

      const result = await service.ingestEvent(
        'test-server',
        undefined,
        makeEnvelope({
          event_name: 'pixel_control.batch.flush',
          event_category: 'batch',
          idempotency_key: 'pc-idem-batch-100',
          payload: { events: [] },
        }),
      );

      expect(batchService.ingestBatchEvent).toHaveBeenCalledOnce();
      // batch envelope is NOT stored in unified Event table (it's a wrapper)
      expect(prisma.event.create).not.toHaveBeenCalled();
      expect(result).toEqual(batchAck);
    });
  });

  describe('Error handling', () => {
    it('returns internal_error response on DB failure', async () => {
      prisma.event.findUnique.mockRejectedValue(new Error('DB exploded'));

      const result = await service.ingestEvent(
        'test-server',
        undefined,
        makeEnvelope(),
      );

      expect(result).toEqual({
        error: {
          code: 'internal_error',
          retryable: true,
          retry_after_seconds: 5,
        },
      });
    });

    it('returns internal_error when event create fails', async () => {
      prisma.server.findUnique.mockResolvedValue({
        id: 'server-uuid',
        serverLogin: 'test-server',
      });
      prisma.event.create.mockRejectedValue(new Error('Insert failed'));

      const result = await service.ingestEvent(
        'test-server',
        undefined,
        makeEnvelope(),
      );

      expect(result).toEqual({
        error: {
          code: 'internal_error',
          retryable: true,
          retry_after_seconds: 5,
        },
      });
    });
  });
});
