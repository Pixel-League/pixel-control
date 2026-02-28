import { NotFoundException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { StatusService } from './status.service';

const makeServer = (overrides = {}) => ({
  id: 'server-uuid',
  serverLogin: 'test-server',
  serverName: 'Test Server',
  linked: true,
  gameMode: 'Elite',
  titleId: 'SMStormElite@nadeolabs',
  pluginVersion: '1.0.0',
  lastHeartbeat: new Date('2026-02-27T14:00:00Z'),
  online: true,
  ...overrides,
});

const makePrismaStub = () => ({
  server: {
    findUnique: vi.fn(),
  },
  connectivityEvent: {
    findFirst: vi.fn(),
    count: vi.fn().mockResolvedValue(0),
  },
  event: {
    groupBy: vi.fn().mockResolvedValue([]),
    findFirst: vi.fn().mockResolvedValue(null),
  },
});

const makeConfigStub = () => ({
  get: vi.fn().mockReturnValue(360),
});

describe('StatusService', () => {
  let service: StatusService;
  let prisma: ReturnType<typeof makePrismaStub>;

  beforeEach(() => {
    prisma = makePrismaStub();
    const config = makeConfigStub();
    service = new StatusService(
      prisma as never,
      config as unknown as ConfigService,
    );
  });

  describe('getServerStatus', () => {
    it('returns full status for known server', async () => {
      prisma.server.findUnique.mockResolvedValue(makeServer());
      prisma.connectivityEvent.findFirst.mockResolvedValue({
        payload: {
          context: { players: { active: 4, total: 6, spectators: 2 } },
        },
        receivedAt: new Date('2026-02-27T14:00:00Z'),
      });
      prisma.event.groupBy.mockResolvedValue([
        { eventCategory: 'connectivity', _count: { id: 24 } },
        { eventCategory: 'lifecycle', _count: { id: 55 } },
      ]);

      const result = await service.getServerStatus('test-server');

      expect(result.server_login).toBe('test-server');
      expect(result.server_name).toBe('Test Server');
      expect(result.linked).toBe(true);
      expect(result.game_mode).toBe('Elite');
      expect(result.title_id).toBe('SMStormElite@nadeolabs');
      expect(result.plugin_version).toBe('1.0.0');
      expect(result.player_counts).toEqual({
        active: 4,
        total: 6,
        spectators: 2,
      });
      expect(result.event_counts.total).toBe(79);
      expect(result.event_counts.by_category['connectivity']).toBe(24);
      expect(result.event_counts.by_category['lifecycle']).toBe(55);
    });

    it('throws NotFoundException for unknown server', async () => {
      prisma.server.findUnique.mockResolvedValue(null);

      await expect(
        service.getServerStatus('unknown-server'),
      ).rejects.toThrow(NotFoundException);
    });

    it('returns online=false when lastHeartbeat is null', async () => {
      prisma.server.findUnique.mockResolvedValue(
        makeServer({ lastHeartbeat: null }),
      );
      prisma.connectivityEvent.findFirst.mockResolvedValue(null);

      const result = await service.getServerStatus('test-server');

      expect(result.online).toBe(false);
    });

    it('returns online=false when lastHeartbeat exceeds threshold', async () => {
      // Set lastHeartbeat to a very old date (way past the 360s threshold)
      const oldDate = new Date(Date.now() - 1000 * 60 * 60); // 1 hour ago
      prisma.server.findUnique.mockResolvedValue(
        makeServer({ lastHeartbeat: oldDate }),
      );
      prisma.connectivityEvent.findFirst.mockResolvedValue(null);

      const result = await service.getServerStatus('test-server');

      expect(result.online).toBe(false);
    });

    it('returns player_counts of zeros when no heartbeat found', async () => {
      prisma.server.findUnique.mockResolvedValue(makeServer());
      prisma.connectivityEvent.findFirst.mockResolvedValue(null);

      const result = await service.getServerStatus('test-server');

      expect(result.player_counts).toEqual({
        active: 0,
        total: 0,
        spectators: 0,
      });
    });

    it('computes event_counts correctly from groupBy results', async () => {
      prisma.server.findUnique.mockResolvedValue(makeServer());
      prisma.connectivityEvent.findFirst.mockResolvedValue(null);
      prisma.event.groupBy.mockResolvedValue([
        { eventCategory: 'connectivity', _count: { id: 10 } },
        { eventCategory: 'combat', _count: { id: 30 } },
        { eventCategory: 'player', _count: { id: 5 } },
      ]);

      const result = await service.getServerStatus('test-server');

      expect(result.event_counts.total).toBe(45);
      expect(result.event_counts.by_category).toEqual({
        connectivity: 10,
        combat: 30,
        player: 5,
      });
    });
  });

  describe('getServerCapabilities', () => {
    it('throws NotFoundException for unknown server', async () => {
      prisma.server.findUnique.mockResolvedValue(null);

      await expect(
        service.getServerCapabilities('unknown-server'),
      ).rejects.toThrow(NotFoundException);
    });

    it('returns capabilities from latest registration event', async () => {
      prisma.server.findUnique.mockResolvedValue(makeServer());
      prisma.connectivityEvent.findFirst.mockImplementation((args: { where: { eventName?: { contains?: string } } }) => {
        if (args?.where?.eventName?.contains === 'registration') {
          return Promise.resolve({
            payload: {
              capabilities: { admin_control: { enabled: true } },
            },
            receivedAt: new Date('2026-02-28T09:00:00Z'),
          });
        }
        return Promise.resolve(null);
      });

      const result = await service.getServerCapabilities('test-server');

      expect(result.capabilities).toEqual({ admin_control: { enabled: true } });
      expect(result.source).toBe('plugin_registration');
    });

    it('falls back to heartbeat when no registration event', async () => {
      prisma.server.findUnique.mockResolvedValue(makeServer());
      prisma.connectivityEvent.findFirst.mockImplementation((args: { where: { eventName?: { contains?: string } } }) => {
        if (args?.where?.eventName?.contains === 'registration') {
          return Promise.resolve(null);
        }
        return Promise.resolve({
          payload: { capabilities: { transport: { mode: 'bearer' } } },
          receivedAt: new Date('2026-02-28T10:00:00Z'),
        });
      });

      const result = await service.getServerCapabilities('test-server');

      expect(result.capabilities).toEqual({ transport: { mode: 'bearer' } });
      expect(result.source).toBe('plugin_heartbeat');
    });

    it('returns null capabilities when no connectivity events', async () => {
      prisma.server.findUnique.mockResolvedValue(makeServer());
      prisma.connectivityEvent.findFirst.mockResolvedValue(null);

      const result = await service.getServerCapabilities('test-server');

      expect(result.capabilities).toBeNull();
      expect(result.source).toBeNull();
      expect(result.source_time).toBeNull();
    });
  });

  describe('getServerHealth', () => {
    it('returns full health for known server', async () => {
      prisma.server.findUnique.mockResolvedValue(makeServer());
      prisma.connectivityEvent.findFirst.mockResolvedValue({
        payload: {
          queue: {
            depth: 0,
            max_size: 2000,
            high_watermark: 3,
            dropped_on_capacity: 0,
            dropped_on_identity_validation: 0,
            recovery_flush_pending: false,
          },
          retry: {
            max_retry_attempts: 3,
            retry_backoff_ms: 250,
            dispatch_batch_size: 3,
          },
          outage: {
            active: false,
            started_at: null,
            failure_count: 0,
            last_error_code: null,
            recovery_flush_pending: false,
          },
        },
        receivedAt: new Date('2026-02-27T14:00:00Z'),
      });
      prisma.connectivityEvent.count.mockResolvedValue(24);

      const result = await service.getServerHealth('test-server');

      expect(result.server_login).toBe('test-server');
      expect(result.plugin_health.queue.depth).toBe(0);
      expect(result.plugin_health.queue.high_watermark).toBe(3);
      expect(result.plugin_health.retry.max_retry_attempts).toBe(3);
      expect(result.plugin_health.outage.active).toBe(false);
    });

    it('throws NotFoundException for unknown server', async () => {
      prisma.server.findUnique.mockResolvedValue(null);

      await expect(
        service.getServerHealth('unknown-server'),
      ).rejects.toThrow(NotFoundException);
    });

    it('returns default health when no heartbeat events exist', async () => {
      prisma.server.findUnique.mockResolvedValue(makeServer());
      prisma.connectivityEvent.findFirst.mockResolvedValue(null);
      prisma.event.findFirst.mockResolvedValue(null);

      const result = await service.getServerHealth('test-server');

      expect(result.plugin_health.queue.depth).toBe(0);
      expect(result.plugin_health.queue.max_size).toBe(2000);
      expect(result.plugin_health.retry.max_retry_attempts).toBe(3);
      expect(result.plugin_health.outage.active).toBe(false);
    });

    it('computes online status correctly from lastHeartbeat', async () => {
      // Recent heartbeat within threshold — should be online
      const recentDate = new Date(Date.now() - 1000 * 60); // 1 minute ago
      prisma.server.findUnique.mockResolvedValue(
        makeServer({ lastHeartbeat: recentDate }),
      );
      prisma.connectivityEvent.findFirst.mockResolvedValue(null);
      prisma.event.findFirst.mockResolvedValue(null);

      const result = await service.getServerHealth('test-server');

      expect(result.online).toBe(true);
    });
  });
});
