import { NotFoundException } from '@nestjs/common';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { StatusController } from './status.controller';
import {
  CapabilitiesResponse,
  ServerHealthResponse,
  ServerStatusResponse,
  StatusService,
} from './status.service';

const makeStatusResponse = (): ServerStatusResponse => ({
  server_login: 'test-server',
  server_name: 'Test Server',
  linked: true,
  online: true,
  game_mode: 'Elite',
  title_id: 'SMStormElite@nadeolabs',
  plugin_version: '1.0.0',
  last_heartbeat: '2026-02-27T14:00:00.000Z',
  player_counts: { active: 4, total: 6, spectators: 2 },
  event_counts: {
    total: 142,
    by_category: {
      connectivity: 24,
      lifecycle: 55,
      combat: 42,
      player: 13,
      mode: 8,
    },
  },
});

const makeHealthResponse = (): ServerHealthResponse => ({
  server_login: 'test-server',
  online: true,
  plugin_health: {
    queue: {
      depth: 0,
      max_size: 2000,
      high_watermark: 12,
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
  connectivity_metrics: {
    total_connectivity_events: 24,
    last_registration_at: '2026-02-27T12:00:00.000Z',
    last_heartbeat_at: '2026-02-27T14:00:00.000Z',
    heartbeat_count: 23,
    registration_count: 1,
  },
});

const makeCapabilitiesResponse = (): CapabilitiesResponse => ({
  server_login: 'test-server',
  online: true,
  capabilities: {
    admin_control: { enabled: true },
    queue: { max_size: 2000 },
    transport: { mode: 'bearer' },
    callbacks: { connectivity: true },
  },
  source: 'plugin_registration',
  source_time: '2026-02-28T09:00:00.000Z',
});

const makeServiceStub = () => ({
  getServerStatus: vi.fn(),
  getServerHealth: vi.fn(),
  getServerCapabilities: vi.fn(),
});

describe('StatusController', () => {
  let controller: StatusController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new StatusController(
      service as unknown as StatusService,
    );
  });

  describe('GET /servers/:serverLogin/status', () => {
    it('returns 200 with correct server status shape', async () => {
      const statusResponse = makeStatusResponse();
      service.getServerStatus.mockResolvedValue(statusResponse);

      const result = await controller.getServerStatus('test-server');

      expect(result).toEqual(statusResponse);
      expect(service.getServerStatus).toHaveBeenCalledWith('test-server');
    });

    it('returns status with all required fields', async () => {
      service.getServerStatus.mockResolvedValue(makeStatusResponse());

      const result = await controller.getServerStatus('test-server');

      expect(result).toHaveProperty('server_login');
      expect(result).toHaveProperty('linked');
      expect(result).toHaveProperty('online');
      expect(result).toHaveProperty('player_counts');
      expect(result).toHaveProperty('event_counts');
      expect(result.player_counts).toHaveProperty('active');
      expect(result.player_counts).toHaveProperty('total');
      expect(result.player_counts).toHaveProperty('spectators');
      expect(result.event_counts).toHaveProperty('total');
      expect(result.event_counts).toHaveProperty('by_category');
    });

    it('throws NotFoundException for unknown server', async () => {
      service.getServerStatus.mockRejectedValue(
        new NotFoundException("Server 'unknown-server' not found"),
      );

      await expect(
        controller.getServerStatus('unknown-server'),
      ).rejects.toThrow(NotFoundException);
    });

    it('wraps unknown errors as NotFoundException', async () => {
      service.getServerStatus.mockRejectedValue(
        new Error('Unexpected database error'),
      );

      await expect(
        controller.getServerStatus('test-server'),
      ).rejects.toThrow(NotFoundException);
    });
  });

  describe('GET /servers/:serverLogin/status/health', () => {
    it('returns 200 with correct health shape', async () => {
      const healthResponse = makeHealthResponse();
      service.getServerHealth.mockResolvedValue(healthResponse);

      const result = await controller.getServerHealth('test-server');

      expect(result).toEqual(healthResponse);
      expect(service.getServerHealth).toHaveBeenCalledWith('test-server');
    });

    it('returns health with all required fields', async () => {
      service.getServerHealth.mockResolvedValue(makeHealthResponse());

      const result = await controller.getServerHealth('test-server');

      expect(result).toHaveProperty('server_login');
      expect(result).toHaveProperty('online');
      expect(result).toHaveProperty('plugin_health');
      expect(result.plugin_health).toHaveProperty('queue');
      expect(result.plugin_health).toHaveProperty('retry');
      expect(result.plugin_health).toHaveProperty('outage');
      expect(result).toHaveProperty('connectivity_metrics');
      expect(result.connectivity_metrics).toHaveProperty('total_connectivity_events');
    });

    it('throws NotFoundException for unknown server', async () => {
      service.getServerHealth.mockRejectedValue(
        new NotFoundException("Server 'unknown-server' not found"),
      );

      await expect(
        controller.getServerHealth('unknown-server'),
      ).rejects.toThrow(NotFoundException);
    });

    it('wraps unknown errors as NotFoundException', async () => {
      service.getServerHealth.mockRejectedValue(
        new Error('Unexpected database error'),
      );

      await expect(
        controller.getServerHealth('test-server'),
      ).rejects.toThrow(NotFoundException);
    });
  });

  describe('GET /servers/:serverLogin/status/capabilities', () => {
    it('returns 200 with capabilities shape', async () => {
      const capResp = makeCapabilitiesResponse();
      service.getServerCapabilities.mockResolvedValue(capResp);

      const result = await controller.getServerCapabilities('test-server');

      expect(result).toEqual(capResp);
      expect(service.getServerCapabilities).toHaveBeenCalledWith('test-server');
    });

    it('returns null capabilities when no connectivity data exists', async () => {
      service.getServerCapabilities.mockResolvedValue({
        server_login: 'test-server',
        online: false,
        capabilities: null,
        source: null,
        source_time: null,
      });

      const result = await controller.getServerCapabilities('test-server');

      expect(result.capabilities).toBeNull();
      expect(result.source).toBeNull();
    });

    it('throws NotFoundException for unknown server', async () => {
      service.getServerCapabilities.mockRejectedValue(
        new NotFoundException("Server 'unknown-server' not found"),
      );

      await expect(
        controller.getServerCapabilities('unknown-server'),
      ).rejects.toThrow(NotFoundException);
    });

    it('wraps unknown errors as NotFoundException', async () => {
      service.getServerCapabilities.mockRejectedValue(
        new Error('Unexpected error'),
      );

      await expect(
        controller.getServerCapabilities('test-server'),
      ).rejects.toThrow(NotFoundException);
    });
  });
});
