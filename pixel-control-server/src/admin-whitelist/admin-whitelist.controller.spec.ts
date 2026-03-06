import { beforeEach, describe, expect, it, vi } from 'vitest';

import { AdminWhitelistController } from './admin-whitelist.controller';

// ---------------------------------------------------------------------------
// Factory helpers
// ---------------------------------------------------------------------------

const makeActionResponse = (overrides: Partial<{ action_name: string; code: string }> = {}) => ({
  action_name: overrides.action_name ?? 'whitelist.list',
  success: true,
  code: overrides.code ?? 'whitelist_listed',
  message: 'OK',
  details: undefined,
});

const makeServiceStub = () => ({
  enableWhitelist: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'whitelist.enable', code: 'whitelist_enabled' })),
  disableWhitelist: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'whitelist.disable', code: 'whitelist_disabled' })),
  addToWhitelist: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'whitelist.add', code: 'whitelist_added' })),
  removeFromWhitelist: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'whitelist.remove', code: 'whitelist_removed' })),
  listWhitelist: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'whitelist.list', code: 'whitelist_listed' })),
  cleanWhitelist: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'whitelist.clean', code: 'whitelist_cleaned' })),
  syncWhitelist: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'whitelist.sync', code: 'whitelist_synced' })),
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('AdminWhitelistController', () => {
  let controller: AdminWhitelistController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new AdminWhitelistController(service as never);
  });

  describe('enableWhitelist', () => {
    it('calls service.enableWhitelist with serverLogin', async () => {
      await controller.enableWhitelist('test-server');
      expect(service.enableWhitelist).toHaveBeenCalledWith('test-server');
    });

    it('returns the action response', async () => {
      const result = await controller.enableWhitelist('test-server');
      expect(result.action_name).toBe('whitelist.enable');
      expect(result.success).toBe(true);
    });
  });

  describe('disableWhitelist', () => {
    it('calls service.disableWhitelist with serverLogin', async () => {
      await controller.disableWhitelist('test-server');
      expect(service.disableWhitelist).toHaveBeenCalledWith('test-server');
    });
  });

  describe('addToWhitelist', () => {
    it('calls service.addToWhitelist with serverLogin and target_login from body', async () => {
      await controller.addToWhitelist('test-server', { target_login: 'player.one' });
      expect(service.addToWhitelist).toHaveBeenCalledWith('test-server', 'player.one');
    });

    it('returns the action response', async () => {
      const result = await controller.addToWhitelist('test-server', { target_login: 'player.one' });
      expect(result.code).toBe('whitelist_added');
    });
  });

  describe('removeFromWhitelist', () => {
    it('calls service.removeFromWhitelist with serverLogin and login param', async () => {
      await controller.removeFromWhitelist('test-server', 'player.one');
      expect(service.removeFromWhitelist).toHaveBeenCalledWith('test-server', 'player.one');
    });

    it('returns the action response', async () => {
      const result = await controller.removeFromWhitelist('test-server', 'player.one');
      expect(result.code).toBe('whitelist_removed');
    });
  });

  describe('listWhitelist', () => {
    it('calls service.listWhitelist with serverLogin', async () => {
      await controller.listWhitelist('test-server');
      expect(service.listWhitelist).toHaveBeenCalledWith('test-server');
    });

    it('returns the action response', async () => {
      const result = await controller.listWhitelist('test-server');
      expect(result.action_name).toBe('whitelist.list');
    });
  });

  describe('cleanWhitelist (bare DELETE — P5.8)', () => {
    it('calls service.cleanWhitelist with serverLogin', async () => {
      await controller.cleanWhitelist('test-server');
      expect(service.cleanWhitelist).toHaveBeenCalledWith('test-server');
    });

    it('returns the action response and does not conflict with remove-by-login', async () => {
      // Ensure cleanWhitelist (bare DELETE) is wired to cleanWhitelist not removeFromWhitelist
      const result = await controller.cleanWhitelist('test-server');
      expect(result.code).toBe('whitelist_cleaned');
      expect(service.removeFromWhitelist).not.toHaveBeenCalled();
    });
  });

  describe('syncWhitelist', () => {
    it('calls service.syncWhitelist with serverLogin', async () => {
      await controller.syncWhitelist('test-server');
      expect(service.syncWhitelist).toHaveBeenCalledWith('test-server');
    });
  });

  describe('error propagation', () => {
    it('propagates service rejection for unknown server', async () => {
      service.enableWhitelist.mockRejectedValue(new Error('server not found'));
      await expect(controller.enableWhitelist('nonexistent-server')).rejects.toThrow('server not found');
    });
  });
});
