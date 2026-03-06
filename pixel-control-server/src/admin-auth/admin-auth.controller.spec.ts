import { beforeEach, describe, expect, it, vi } from 'vitest';

import { AdminAuthController } from './admin-auth.controller';

// ---------------------------------------------------------------------------
// Factory helpers
// ---------------------------------------------------------------------------

const makeActionResponse = (overrides: Partial<{ action_name: string; code: string }> = {}) => ({
  action_name: overrides.action_name ?? 'auth.grant',
  success: true,
  code: overrides.code ?? 'auth_granted',
  message: 'OK',
  details: undefined,
});

const makeServiceStub = () => ({
  grantAuth: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'auth.grant', code: 'auth_granted' })),
  revokeAuth: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'auth.revoke', code: 'auth_revoked' })),
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('AdminAuthController', () => {
  let controller: AdminAuthController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new AdminAuthController(service as never);
  });

  describe('grantAuth', () => {
    it('calls service.grantAuth with serverLogin, playerLogin, and auth_level', async () => {
      await controller.grantAuth('test-server', 'player.login', { auth_level: 'admin' });
      expect(service.grantAuth).toHaveBeenCalledWith('test-server', 'player.login', 'admin');
    });

    it('returns the action response', async () => {
      const result = await controller.grantAuth('test-server', 'player.login', { auth_level: 'moderator' });
      expect(result.action_name).toBe('auth.grant');
      expect(result.success).toBe(true);
      expect(result.code).toBe('auth_granted');
    });

    it('propagates service rejection', async () => {
      service.grantAuth.mockRejectedValue(new Error('not found'));
      await expect(
        controller.grantAuth('missing-server', 'player.login', { auth_level: 'admin' }),
      ).rejects.toThrow('not found');
    });
  });

  describe('revokeAuth', () => {
    it('calls service.revokeAuth with serverLogin and playerLogin', async () => {
      await controller.revokeAuth('test-server', 'player.login');
      expect(service.revokeAuth).toHaveBeenCalledWith('test-server', 'player.login');
    });

    it('returns the action response', async () => {
      const result = await controller.revokeAuth('test-server', 'player.login');
      expect(result.action_name).toBe('auth.revoke');
      expect(result.code).toBe('auth_revoked');
    });

    it('propagates service rejection for unknown server', async () => {
      service.revokeAuth.mockRejectedValue(new Error('server not found'));
      await expect(
        controller.revokeAuth('nonexistent-server', 'player.login'),
      ).rejects.toThrow('server not found');
    });
  });
});
