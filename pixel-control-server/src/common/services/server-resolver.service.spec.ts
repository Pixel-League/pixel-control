import { NotFoundException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ServerResolverService } from './server-resolver.service';

const makeServer = (overrides = {}) => ({
  id: 'server-uuid',
  serverLogin: 'test-server',
  serverName: 'Test Server',
  linked: true,
  gameMode: 'Elite',
  titleId: 'SMStormElite@nadeolabs',
  pluginVersion: '1.0.0',
  lastHeartbeat: new Date(Date.now() - 1000 * 30), // 30 seconds ago
  online: true,
  linkToken: 'token-xyz',
  createdAt: new Date('2026-02-28T00:00:00Z'),
  updatedAt: new Date('2026-02-28T00:00:00Z'),
  ...overrides,
});

const makePrismaStub = () => ({
  server: {
    findUnique: vi.fn(),
  },
});

const makeConfigStub = () => ({
  get: vi.fn().mockReturnValue(360),
});

describe('ServerResolverService', () => {
  let service: ServerResolverService;
  let prisma: ReturnType<typeof makePrismaStub>;

  beforeEach(() => {
    prisma = makePrismaStub();
    const config = makeConfigStub();
    service = new ServerResolverService(
      prisma as never,
      config as unknown as ConfigService,
    );
  });

  describe('resolve', () => {
    it('returns server and online=true for recently active server', async () => {
      const server = makeServer();
      prisma.server.findUnique.mockResolvedValue(server);

      const result = await service.resolve('test-server');

      expect(result.server).toEqual(server);
      expect(result.online).toBe(true);
    });

    it('throws NotFoundException for unknown server', async () => {
      prisma.server.findUnique.mockResolvedValue(null);

      await expect(service.resolve('unknown-server')).rejects.toThrow(NotFoundException);
      await expect(service.resolve('unknown-server')).rejects.toThrow(
        "Server 'unknown-server' not found",
      );
    });

    it('returns online=false when lastHeartbeat is null', async () => {
      prisma.server.findUnique.mockResolvedValue(makeServer({ lastHeartbeat: null }));

      const result = await service.resolve('test-server');

      expect(result.online).toBe(false);
    });

    it('returns online=false when lastHeartbeat exceeds threshold', async () => {
      const oldDate = new Date(Date.now() - 1000 * 60 * 60); // 1 hour ago
      prisma.server.findUnique.mockResolvedValue(makeServer({ lastHeartbeat: oldDate }));

      const result = await service.resolve('test-server');

      expect(result.online).toBe(false);
    });

    it('queries by serverLogin', async () => {
      prisma.server.findUnique.mockResolvedValue(makeServer());

      await service.resolve('my-server');

      expect(prisma.server.findUnique).toHaveBeenCalledWith({
        where: { serverLogin: 'my-server' },
      });
    });
  });
});
