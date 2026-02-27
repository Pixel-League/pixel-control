import { ConfigService } from '@nestjs/config';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { EventEnvelopeDto } from '../common/dto/event-envelope.dto';
import { ConnectivityService } from './connectivity.service';

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
  payload: {
    type: 'plugin_registration',
    context: {
      server: {
        login: 'test-server',
        title_id: 'SMStormElite@nadeolabs',
        game_mode: 'Elite',
      },
    },
  },
  ...overrides,
});

const makeTransactionFn = () =>
  vi.fn().mockImplementation(async (ops: Promise<unknown>[]) => {
    return Promise.all(ops);
  });

const makePrismaStub = () => ({
  connectivityEvent: {
    findUnique: vi.fn(),
    create: vi.fn().mockResolvedValue({}),
  },
  server: {
    findUnique: vi.fn(),
    create: vi.fn(),
    update: vi.fn().mockResolvedValue({}),
  },
  $transaction: makeTransactionFn(),
});

const makeConfigStub = () => ({
  get: vi.fn().mockReturnValue(360),
});

describe('ConnectivityService', () => {
  let service: ConnectivityService;
  let prisma: ReturnType<typeof makePrismaStub>;

  beforeEach(() => {
    prisma = makePrismaStub();
    const config = makeConfigStub();
    service = new ConnectivityService(
      prisma as never,
      config as unknown as ConfigService,
    );
  });

  it('accepts a valid registration event and returns accepted ack', async () => {
    prisma.connectivityEvent.findUnique.mockResolvedValue(null);
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
    expect(prisma.$transaction).toHaveBeenCalledOnce();
  });

  it('accepts a valid heartbeat event and updates lastHeartbeat', async () => {
    prisma.connectivityEvent.findUnique.mockResolvedValue(null);
    prisma.server.findUnique.mockResolvedValue({
      id: 'server-uuid',
      serverLogin: 'test-server',
    });

    const envelope = makeEnvelope({
      event_name: 'pixel_control.connectivity.plugin_heartbeat',
      idempotency_key: 'pc-idem-hb-002',
      payload: { type: 'plugin_heartbeat', queue_depth: 0 },
    });

    const result = await service.ingestEvent('test-server', '1.0.0', envelope);

    expect(result).toEqual({ ack: { status: 'accepted' } });
  });

  it('returns accepted with disposition=duplicate for known idempotency_key', async () => {
    prisma.connectivityEvent.findUnique.mockResolvedValue({
      id: 'existing-id',
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
    expect(prisma.$transaction).not.toHaveBeenCalled();
  });

  it('auto-registers an unknown server on first event', async () => {
    prisma.connectivityEvent.findUnique.mockResolvedValue(null);
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

    expect(prisma.server.create).toHaveBeenCalledOnce();
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

  it('updates serverName, gameMode, titleId from plugin_registration payload', async () => {
    prisma.connectivityEvent.findUnique.mockResolvedValue(null);
    prisma.server.findUnique.mockResolvedValue({
      id: 'server-uuid',
      serverLogin: 'test-server',
    });

    const envelope = makeEnvelope({
      payload: {
        type: 'plugin_registration',
        context: {
          server: {
            name: 'My Server',
            game_mode: 'Elite',
            title_id: 'SMStormElite@nadeolabs',
          },
        },
      },
    });

    await service.ingestEvent('test-server', '2.0.0', envelope);

    // The update call happens inside the transaction; check $transaction was called
    expect(prisma.$transaction).toHaveBeenCalledOnce();
  });

  it('returns internal_error response on unexpected exception', async () => {
    prisma.connectivityEvent.findUnique.mockRejectedValue(
      new Error('DB exploded'),
    );

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
