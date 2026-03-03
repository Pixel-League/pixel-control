import {
  BadGatewayException,
  BadRequestException,
  ForbiddenException,
  NotFoundException,
} from '@nestjs/common';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { AdminProxyService } from './admin-proxy.service';

// ---------------------------------------------------------------------------
// Factory helpers
// ---------------------------------------------------------------------------

const makeServer = (overrides: Partial<{ linked: boolean; linkToken: string | null }> = {}) => ({
  id: 1,
  serverLogin: 'test-server',
  serverName: null,
  linked: overrides.linked ?? true,
  linkToken: overrides.linkToken !== undefined ? overrides.linkToken : 'valid-token-xyz',
  gameMode: 'Elite',
  titleId: 'ShootMania',
  pluginVersion: '1.0.0',
  lastHeartbeat: new Date(),
  online: true,
  createdAt: new Date(),
  updatedAt: new Date(),
});

const makeSocketResult = (
  overrides: {
    error?: boolean;
    success?: boolean;
    code?: string;
    message?: string;
    details?: Record<string, unknown>;
  } = {},
) => ({
  error: overrides.error ?? false,
  data: {
    action_name: 'map.skip',
    success: overrides.success ?? true,
    code: overrides.code ?? 'map_skipped',
    message: overrides.message ?? 'Map skipped',
    details: overrides.details,
  },
});

const makeSocketClient = () => ({
  sendCommand: vi.fn().mockResolvedValue(makeSocketResult()),
});

const makeServerResolver = () => ({
  resolve: vi.fn().mockResolvedValue({ server: makeServer(), online: true }),
});

const makeConfig = (overrides: Partial<{ host: string; port: number; password: string }> = {}) => ({
  get: vi.fn((key: string) => {
    if (key === 'MC_SOCKET_HOST') return overrides.host ?? '127.0.0.1';
    if (key === 'MC_SOCKET_PORT') return overrides.port ?? 31501;
    if (key === 'MC_SOCKET_PASSWORD') return overrides.password ?? 'test-password';
    return undefined;
  }),
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('AdminProxyService', () => {
  let service: AdminProxyService;
  let socketClient: ReturnType<typeof makeSocketClient>;
  let serverResolver: ReturnType<typeof makeServerResolver>;
  let config: ReturnType<typeof makeConfig>;

  beforeEach(() => {
    socketClient = makeSocketClient();
    serverResolver = makeServerResolver();
    config = makeConfig();
    service = new AdminProxyService(
      socketClient as never,
      serverResolver as never,
      config as never,
    );
  });

  // ─── executeAction ────────────────────────────────────────────────────────────

  describe('executeAction', () => {
    it('resolves the server and sends the correct socket payload', async () => {
      await service.executeAction('test-server', 'map.skip');
      expect(serverResolver.resolve).toHaveBeenCalledWith('test-server');
      expect(socketClient.sendCommand).toHaveBeenCalledWith(
        '127.0.0.1',
        31501,
        'test-password',
        'PixelControl.Admin.ExecuteAction',
        expect.objectContaining({
          action: 'map.skip',
          server_login: 'test-server',
          auth: { mode: 'link_bearer', token: 'valid-token-xyz' },
        }),
      );
    });

    it('includes parameters in the socket payload when provided', async () => {
      await service.executeAction('test-server', 'map.jump', { map_uid: 'uid-abc' });
      expect(socketClient.sendCommand).toHaveBeenCalledWith(
        expect.any(String),
        expect.any(Number),
        expect.any(String),
        'PixelControl.Admin.ExecuteAction',
        expect.objectContaining({ parameters: { map_uid: 'uid-abc' } }),
      );
    });

    it('omits parameters key when parameters are empty', async () => {
      await service.executeAction('test-server', 'map.skip');
      const call = socketClient.sendCommand.mock.calls[0] as unknown[];
      const payload = call[4] as Record<string, unknown>;
      expect('parameters' in payload).toBe(false);
    });

    it('returns the AdminActionResponse on success', async () => {
      const result = await service.executeAction('test-server', 'map.skip');
      expect(result.action_name).toBe('map.skip');
      expect(result.success).toBe(true);
      expect(result.code).toBe('map_skipped');
    });

    it('throws NotFoundException when server resolver throws 404', async () => {
      serverResolver.resolve.mockRejectedValue(new NotFoundException('Server not found'));
      await expect(service.executeAction('unknown-server', 'map.skip')).rejects.toThrow(NotFoundException);
    });

    it('throws BadGatewayException when socket returns error=true', async () => {
      socketClient.sendCommand.mockResolvedValue({
        error: true,
        data: { code: 'socket_timeout', message: 'Connection timeout' },
      });
      await expect(service.executeAction('test-server', 'map.skip')).rejects.toThrow(BadGatewayException);
    });

    it('throws BadGatewayException when socket call throws', async () => {
      socketClient.sendCommand.mockRejectedValue(new Error('ECONNREFUSED'));
      await expect(service.executeAction('test-server', 'map.skip')).rejects.toThrow(BadGatewayException);
    });

    it('throws ForbiddenException on auth error code link_auth_invalid', async () => {
      socketClient.sendCommand.mockResolvedValue(
        makeSocketResult({ success: false, code: 'link_auth_invalid', message: 'Invalid token' }),
      );
      await expect(service.executeAction('test-server', 'map.skip')).rejects.toThrow(ForbiddenException);
    });

    it('throws ForbiddenException on auth error code link_server_mismatch', async () => {
      socketClient.sendCommand.mockResolvedValue(
        makeSocketResult({ success: false, code: 'link_server_mismatch', message: 'Server mismatch' }),
      );
      await expect(service.executeAction('test-server', 'map.skip')).rejects.toThrow(ForbiddenException);
    });

    it('throws BadRequestException on client error code map_not_found', async () => {
      socketClient.sendCommand.mockResolvedValue(
        makeSocketResult({ success: false, code: 'map_not_found', message: 'Map not found' }),
      );
      await expect(service.executeAction('test-server', 'map.jump')).rejects.toThrow(BadRequestException);
    });

    it('throws BadRequestException on client error code invalid_parameter', async () => {
      socketClient.sendCommand.mockResolvedValue(
        makeSocketResult({ success: false, code: 'invalid_parameter', message: 'Bad param' }),
      );
      await expect(service.executeAction('test-server', 'warmup.extend')).rejects.toThrow(BadRequestException);
    });

    it('throws NotFoundException on action_not_found', async () => {
      socketClient.sendCommand.mockResolvedValue(
        makeSocketResult({ success: false, code: 'action_not_found', message: 'Unknown action' }),
      );
      await expect(service.executeAction('test-server', 'unknown.action')).rejects.toThrow(NotFoundException);
    });

    it('throws BadGatewayException on unknown plugin error', async () => {
      socketClient.sendCommand.mockResolvedValue(
        makeSocketResult({ success: false, code: 'plugin_internal_error', message: 'Crash' }),
      );
      await expect(service.executeAction('test-server', 'map.skip')).rejects.toThrow(BadGatewayException);
    });

    it('injects empty string token when linkToken is null', async () => {
      serverResolver.resolve.mockResolvedValue({
        server: makeServer({ linkToken: null }),
        online: false,
      });
      await service.executeAction('test-server', 'map.skip');
      const call = socketClient.sendCommand.mock.calls[0] as unknown[];
      const payload = call[4] as Record<string, unknown>;
      expect((payload['auth'] as Record<string, unknown>)['token']).toBe('');
    });
  });

  // ─── queryAction ─────────────────────────────────────────────────────────────

  describe('queryAction', () => {
    it('returns the response even when success=false (does not throw)', async () => {
      socketClient.sendCommand.mockResolvedValue(
        makeSocketResult({ success: false, code: 'some_error', message: 'Query failed' }),
      );
      const result = await service.queryAction('test-server', 'match.bo.get');
      expect(result.success).toBe(false);
      expect(result.code).toBe('some_error');
    });

    it('still throws BadGatewayException when socket returns error=true', async () => {
      socketClient.sendCommand.mockResolvedValue({
        error: true,
        data: { code: 'socket_timeout', message: 'Timeout' },
      });
      await expect(service.queryAction('test-server', 'match.bo.get')).rejects.toThrow(BadGatewayException);
    });

    it('returns details from plugin response', async () => {
      socketClient.sendCommand.mockResolvedValue({
        error: false,
        data: {
          action_name: 'match.bo.get',
          success: true,
          code: 'match_bo_retrieved',
          message: 'OK',
          details: { best_of: 3 },
        },
      });
      const result = await service.queryAction('test-server', 'match.bo.get');
      expect(result.details).toEqual({ best_of: 3 });
    });
  });
});
