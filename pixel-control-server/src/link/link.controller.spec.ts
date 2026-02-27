import { NotFoundException } from '@nestjs/common';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { LinkController } from './link.controller';
import { LinkService } from './link.service';

const makeLinkServiceStub = () => ({
  registerServer: vi.fn(),
  generateToken: vi.fn(),
  getAuthState: vi.fn(),
  checkAccess: vi.fn(),
  listServers: vi.fn(),
});

describe('LinkController', () => {
  let controller: LinkController;
  let service: ReturnType<typeof makeLinkServiceStub>;

  beforeEach(() => {
    service = makeLinkServiceStub();
    controller = new LinkController(service as unknown as LinkService);
  });

  describe('registerServer (PUT /servers/:serverLogin/link/registration)', () => {
    it('delegates to LinkService.registerServer and returns the result', async () => {
      const expected = { server_login: 'srv1', registered: true, link_token: 'tok' };
      service.registerServer.mockResolvedValue(expected);

      const result = await controller.registerServer('srv1', {
        server_name: 'Test',
      });

      expect(service.registerServer).toHaveBeenCalledWith('srv1', {
        server_name: 'Test',
      });
      expect(result).toEqual(expected);
    });
  });

  describe('generateToken (POST /servers/:serverLogin/link/token)', () => {
    it('delegates to LinkService.generateToken', async () => {
      const expected = { server_login: 'srv1', link_token: 'new-tok', rotated: true };
      service.generateToken.mockResolvedValue(expected);

      const result = await controller.generateToken('srv1', { rotate: true });

      expect(service.generateToken).toHaveBeenCalledWith('srv1', {
        rotate: true,
      });
      expect(result).toEqual(expected);
    });

    it('propagates NotFoundException from service', async () => {
      service.generateToken.mockRejectedValue(new NotFoundException());

      await expect(
        controller.generateToken('unknown', {}),
      ).rejects.toThrow(NotFoundException);
    });
  });

  describe('getAuthState (GET /servers/:serverLogin/link/auth-state)', () => {
    it('delegates to LinkService.getAuthState', async () => {
      const expected = {
        server_login: 'srv1',
        linked: true,
        last_heartbeat: null,
        plugin_version: null,
        online: false,
      };
      service.getAuthState.mockResolvedValue(expected);

      const result = await controller.getAuthState('srv1');

      expect(service.getAuthState).toHaveBeenCalledWith('srv1');
      expect(result).toEqual(expected);
    });
  });

  describe('checkAccess (GET /servers/:serverLogin/link/access)', () => {
    it('delegates to LinkService.checkAccess', async () => {
      const expected = {
        server_login: 'srv1',
        access_granted: true,
        linked: true,
        online: false,
      };
      service.checkAccess.mockResolvedValue(expected);

      const result = await controller.checkAccess('srv1');

      expect(result).toEqual(expected);
    });
  });

  describe('listServers (GET /servers)', () => {
    it('delegates to LinkService.listServers with no status filter', async () => {
      service.listServers.mockResolvedValue([]);

      await controller.listServers({});

      expect(service.listServers).toHaveBeenCalledWith(undefined);
    });

    it('passes status filter to LinkService.listServers', async () => {
      service.listServers.mockResolvedValue([]);

      await controller.listServers({ status: 'linked' as never });

      expect(service.listServers).toHaveBeenCalledWith('linked');
    });
  });
});
