import {
  BadGatewayException,
  ForbiddenException,
  NotFoundException,
} from '@nestjs/common';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { VetoDraftProxyService } from './veto-draft-proxy.service';

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
  } = {},
) => ({
  error: overrides.error ?? false,
  data: {
    success: overrides.success ?? true,
    code: overrides.code ?? 'ok',
    message: overrides.message ?? 'OK',
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

describe('VetoDraftProxyService', () => {
  let service: VetoDraftProxyService;
  let socketClient: ReturnType<typeof makeSocketClient>;
  let serverResolver: ReturnType<typeof makeServerResolver>;
  let config: ReturnType<typeof makeConfig>;

  beforeEach(() => {
    socketClient = makeSocketClient();
    serverResolver = makeServerResolver();
    config = makeConfig();
    service = new VetoDraftProxyService(
      socketClient as never,
      serverResolver as never,
      config as never,
    );
  });

  // ─── sendVetoDraftCommand ─────────────────────────────────────────────────

  describe('sendVetoDraftCommand', () => {
    it('resolves the server and sends with the correct method name', async () => {
      await service.sendVetoDraftCommand('test-server', 'Status');
      expect(serverResolver.resolve).toHaveBeenCalledWith('test-server');
      expect(socketClient.sendCommand).toHaveBeenCalledWith(
        '127.0.0.1',
        31501,
        'test-password',
        'PixelControl.VetoDraft.Status',
        expect.objectContaining({
          server_login: 'test-server',
          auth: { mode: 'link_bearer', token: 'valid-token-xyz' },
        }),
      );
    });

    it('constructs method name as PixelControl.VetoDraft.<suffix>', async () => {
      await service.sendVetoDraftCommand('test-server', 'Start');
      const call = socketClient.sendCommand.mock.calls[0] as unknown[];
      expect(call[3]).toBe('PixelControl.VetoDraft.Start');
    });

    it('merges additional data into the payload', async () => {
      await service.sendVetoDraftCommand('test-server', 'Start', { mode: 'tournament_draft' });
      expect(socketClient.sendCommand).toHaveBeenCalledWith(
        expect.any(String),
        expect.any(Number),
        expect.any(String),
        'PixelControl.VetoDraft.Start',
        expect.objectContaining({ mode: 'tournament_draft' }),
      );
    });

    it('returns the successful response with success, code, and message', async () => {
      const result = await service.sendVetoDraftCommand('test-server', 'Ready');
      expect(result.success).toBe(true);
      expect(result.code).toBe('ok');
      expect(result.message).toBe('OK');
    });

    it('throws NotFoundException when server resolver throws 404', async () => {
      serverResolver.resolve.mockRejectedValue(new NotFoundException('Server not found'));
      await expect(service.sendVetoDraftCommand('unknown', 'Status')).rejects.toThrow(NotFoundException);
    });

    it('throws BadGatewayException when socket returns error=true', async () => {
      socketClient.sendCommand.mockResolvedValue({
        error: true,
        data: { code: 'socket_timeout', message: 'Connection timeout' },
      });
      await expect(service.sendVetoDraftCommand('test-server', 'Status')).rejects.toThrow(BadGatewayException);
    });

    it('throws BadGatewayException when socket call throws', async () => {
      socketClient.sendCommand.mockRejectedValue(new Error('ECONNREFUSED'));
      await expect(service.sendVetoDraftCommand('test-server', 'Cancel')).rejects.toThrow(BadGatewayException);
    });

    it('throws ForbiddenException on auth error code link_auth_invalid', async () => {
      socketClient.sendCommand.mockResolvedValue(
        makeSocketResult({ success: false, code: 'link_auth_invalid', message: 'Invalid token' }),
      );
      await expect(service.sendVetoDraftCommand('test-server', 'Start')).rejects.toThrow(ForbiddenException);
    });

    it('throws ForbiddenException on auth error code link_server_mismatch', async () => {
      socketClient.sendCommand.mockResolvedValue(
        makeSocketResult({ success: false, code: 'link_server_mismatch', message: 'Mismatch' }),
      );
      await expect(service.sendVetoDraftCommand('test-server', 'Action')).rejects.toThrow(ForbiddenException);
    });

    it('injects empty string token when linkToken is null', async () => {
      serverResolver.resolve.mockResolvedValue({
        server: makeServer({ linkToken: null }),
        online: false,
      });
      await service.sendVetoDraftCommand('test-server', 'Status');
      const call = socketClient.sendCommand.mock.calls[0] as unknown[];
      const payload = call[4] as Record<string, unknown>;
      expect((payload['auth'] as Record<string, unknown>)['token']).toBe('');
    });
  });

  // ─── queryVetoDraftStatus ─────────────────────────────────────────────────

  describe('queryVetoDraftStatus', () => {
    it('sends Status method and returns the response', async () => {
      const result = await service.queryVetoDraftStatus('test-server');
      expect(socketClient.sendCommand).toHaveBeenCalledWith(
        expect.any(String),
        expect.any(Number),
        expect.any(String),
        'PixelControl.VetoDraft.Status',
        expect.any(Object),
      );
      expect(result.success).toBe(true);
    });

    it('throws BadGatewayException when socket unavailable', async () => {
      socketClient.sendCommand.mockResolvedValue({
        error: true,
        data: { code: 'socket_error', message: 'Socket error' },
      });
      await expect(service.queryVetoDraftStatus('test-server')).rejects.toThrow(BadGatewayException);
    });
  });
});
