import { NotFoundException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { LinkService } from './link.service';

const makePrismaStub = () => ({
  server: {
    findUnique: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    delete: vi.fn(),
    findMany: vi.fn(),
  },
  connectivityEvent: {
    deleteMany: vi.fn(),
  },
});

const makeConfigStub = () => ({
  get: vi.fn().mockReturnValue(360),
});

describe('LinkService', () => {
  let service: LinkService;
  let prisma: ReturnType<typeof makePrismaStub>;

  beforeEach(() => {
    prisma = makePrismaStub();
    const config = makeConfigStub();
    service = new LinkService(
      prisma as never,
      config as unknown as ConfigService,
    );
  });

  // ------------------------------------------------------------------
  // registerServer
  // ------------------------------------------------------------------
  describe('registerServer', () => {
    it('creates a new server with link token on first registration', async () => {
      prisma.server.findUnique.mockResolvedValue(null);
      prisma.server.create.mockResolvedValue({ serverLogin: 'srv1' });

      const result = await service.registerServer('srv1', {
        server_name: 'Test Server',
      });

      expect(prisma.server.create).toHaveBeenCalledOnce();
      expect(result.registered).toBe(true);
      expect(result.link_token).toBeDefined();
      expect(typeof result.link_token).toBe('string');
    });

    it('updates existing server without generating a new token', async () => {
      prisma.server.findUnique.mockResolvedValue({
        serverLogin: 'srv1',
        linkToken: 'existing-token',
        linked: true,
      });
      prisma.server.update.mockResolvedValue({});

      const result = await service.registerServer('srv1', {
        server_name: 'Updated Name',
      });

      expect(prisma.server.create).not.toHaveBeenCalled();
      expect(prisma.server.update).toHaveBeenCalledOnce();
      expect(result.registered).toBe(true);
      expect(result.link_token).toBeUndefined();
    });

    it('returns current state without DB update when no body fields provided', async () => {
      prisma.server.findUnique.mockResolvedValue({
        serverLogin: 'srv1',
        linkToken: 'existing-token',
        linked: true,
      });

      const result = await service.registerServer('srv1', {});

      expect(prisma.server.update).not.toHaveBeenCalled();
      expect(result.registered).toBe(true);
    });
  });

  // ------------------------------------------------------------------
  // generateToken
  // ------------------------------------------------------------------
  describe('generateToken', () => {
    it('returns existing token when rotate is falsy', async () => {
      prisma.server.findUnique.mockResolvedValue({
        serverLogin: 'srv1',
        linkToken: 'my-token',
      });

      const result = await service.generateToken('srv1', {});

      expect(result.link_token).toBe('my-token');
      expect(result.rotated).toBe(false);
    });

    it('rotates token when rotate is true', async () => {
      const oldToken = 'old-token';
      prisma.server.findUnique.mockResolvedValue({
        serverLogin: 'srv1',
        linkToken: oldToken,
      });
      prisma.server.update.mockResolvedValue({});

      const result = await service.generateToken('srv1', { rotate: true });

      expect(result.link_token).not.toBe(oldToken);
      expect(result.rotated).toBe(true);
      expect(prisma.server.update).toHaveBeenCalledOnce();
    });

    it('generates token when server has no token and rotate is not set', async () => {
      prisma.server.findUnique.mockResolvedValue({
        serverLogin: 'srv1',
        linkToken: null,
      });
      prisma.server.update.mockResolvedValue({});

      const result = await service.generateToken('srv1', {});

      expect(result.link_token).toBeDefined();
      expect(result.rotated).toBe(true);
    });

    it('throws NotFoundException for unknown server', async () => {
      prisma.server.findUnique.mockResolvedValue(null);

      await expect(service.generateToken('unknown', {})).rejects.toThrow(
        NotFoundException,
      );
    });
  });

  // ------------------------------------------------------------------
  // getAuthState
  // ------------------------------------------------------------------
  describe('getAuthState', () => {
    it('returns correct auth state with online=true for recent heartbeat', async () => {
      const recentHeartbeat = new Date(Date.now() - 60_000);
      prisma.server.findUnique.mockResolvedValue({
        serverLogin: 'srv1',
        linked: true,
        lastHeartbeat: recentHeartbeat,
        pluginVersion: '1.0.0',
      });

      const result = await service.getAuthState('srv1');

      expect(result.linked).toBe(true);
      expect(result.online).toBe(true);
      expect(result.plugin_version).toBe('1.0.0');
      expect(result.last_heartbeat).toBe(recentHeartbeat.toISOString());
    });

    it('returns online=false when no heartbeat', async () => {
      prisma.server.findUnique.mockResolvedValue({
        serverLogin: 'srv1',
        linked: true,
        lastHeartbeat: null,
        pluginVersion: null,
      });

      const result = await service.getAuthState('srv1');

      expect(result.online).toBe(false);
      expect(result.last_heartbeat).toBeNull();
    });

    it('throws NotFoundException for unknown server', async () => {
      prisma.server.findUnique.mockResolvedValue(null);

      await expect(service.getAuthState('unknown')).rejects.toThrow(
        NotFoundException,
      );
    });
  });

  // ------------------------------------------------------------------
  // checkAccess
  // ------------------------------------------------------------------
  describe('checkAccess', () => {
    it('returns access_granted=true when linked', async () => {
      prisma.server.findUnique.mockResolvedValue({
        serverLogin: 'srv1',
        linked: true,
        lastHeartbeat: new Date(Date.now() - 60_000),
      });

      const result = await service.checkAccess('srv1');

      expect(result.access_granted).toBe(true);
      expect(result.linked).toBe(true);
      expect(result.online).toBe(true);
    });

    it('returns access_granted=false when not linked', async () => {
      prisma.server.findUnique.mockResolvedValue({
        serverLogin: 'srv1',
        linked: false,
        lastHeartbeat: null,
      });

      const result = await service.checkAccess('srv1');

      expect(result.access_granted).toBe(false);
    });

    it('throws NotFoundException for unknown server', async () => {
      prisma.server.findUnique.mockResolvedValue(null);

      await expect(service.checkAccess('unknown')).rejects.toThrow(
        NotFoundException,
      );
    });
  });

  // ------------------------------------------------------------------
  // deleteServer
  // ------------------------------------------------------------------
  describe('deleteServer', () => {
    it('deletes an existing server and its connectivity events', async () => {
      prisma.server.findUnique.mockResolvedValue({
        id: 'uuid-1',
        serverLogin: 'srv1',
        linked: true,
      });
      prisma.connectivityEvent.deleteMany.mockResolvedValue({ count: 3 });
      prisma.server.delete.mockResolvedValue({});

      const result = await service.deleteServer('srv1');

      expect(prisma.connectivityEvent.deleteMany).toHaveBeenCalledWith({
        where: { serverId: 'uuid-1' },
      });
      expect(prisma.server.delete).toHaveBeenCalledWith({
        where: { serverLogin: 'srv1' },
      });
      expect(result.deleted).toBe(true);
      expect(result.server_login).toBe('srv1');
    });

    it('throws NotFoundException for unknown server', async () => {
      prisma.server.findUnique.mockResolvedValue(null);

      await expect(service.deleteServer('unknown')).rejects.toThrow(
        NotFoundException,
      );
    });
  });

  // ------------------------------------------------------------------
  // listServers
  // ------------------------------------------------------------------
  describe('listServers', () => {
    const servers = [
      {
        serverLogin: 'srv1',
        serverName: 'Server 1',
        linked: true,
        lastHeartbeat: new Date(Date.now() - 60_000),
        pluginVersion: '1.0.0',
        gameMode: 'Elite',
        titleId: 'SMStormElite@nadeolabs',
        createdAt: new Date(),
        updatedAt: new Date(),
      },
      {
        serverLogin: 'srv2',
        serverName: 'Server 2',
        linked: false,
        lastHeartbeat: null,
        pluginVersion: null,
        gameMode: null,
        titleId: null,
        createdAt: new Date(),
        updatedAt: new Date(),
      },
    ];

    it('returns all servers when no status filter', async () => {
      prisma.server.findMany.mockResolvedValue(servers);

      const result = await service.listServers();

      expect(result).toHaveLength(2);
    });

    it('filters to linked servers only', async () => {
      prisma.server.findMany.mockResolvedValue(servers);

      const result = await service.listServers('linked');

      expect(result).toHaveLength(1);
      expect(result[0].server_login).toBe('srv1');
    });

    it('filters to offline servers only', async () => {
      prisma.server.findMany.mockResolvedValue(servers);

      const result = await service.listServers('offline');

      // srv2 has no heartbeat (offline), srv1 has a recent heartbeat (online)
      expect(result.every((s) => !s.online)).toBe(true);
    });

    it('computes online dynamically from heartbeat', async () => {
      prisma.server.findMany.mockResolvedValue([servers[0]]);

      const result = await service.listServers();

      expect(result[0].online).toBe(true);
    });
  });
});
