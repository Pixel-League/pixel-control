import { BadRequestException, ForbiddenException, NotFoundException } from '@nestjs/common';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ServerStateController } from './server-state.controller';
import { ServerStateSnapshotDto } from './dto/server-state.dto';

// ---------------------------------------------------------------------------
// Factory helpers
// ---------------------------------------------------------------------------

const makeSnapshot = (overrides: Partial<ServerStateSnapshotDto> = {}): ServerStateSnapshotDto => ({
  state_version: '1.0',
  captured_at: 1741276800,
  admin: {
    current_best_of: 3,
    team_maps_score: { team_a: 0, team_b: 0 },
    team_round_score: { team_a: 0, team_b: 0 },
    team_policy_enabled: false,
    team_switch_lock: false,
    team_roster: {},
    whitelist_enabled: false,
    whitelist: [],
    vote_policy: 'default',
    vote_ratios: {},
  },
  veto_draft: {
    session: null,
    matchmaking_ready_armed: false,
    votes: {},
  },
  ...overrides,
});

const makeSaveStateResponse = (updatedAt = '2026-03-06T00:00:00.000Z') => ({
  saved: true,
  updated_at: updatedAt,
});

const makeGetStateResponse = (
  state: Record<string, unknown> | null = null,
  updatedAt: string | null = null,
  source: 'saved' | 'template' | 'default' = 'default',
) => ({
  state,
  updated_at: updatedAt,
  source,
});

const makeApplyTemplateResponse = (overrides: Partial<{
  template_id: string;
  template_name: string;
  updated_at: string;
}> = {}) => ({
  applied: true,
  template_id: overrides.template_id ?? 'tpl-uuid-1',
  template_name: overrides.template_name ?? 'Elite Standard',
  updated_at: overrides.updated_at ?? '2026-03-06T00:00:00.000Z',
});

const makeServiceStub = () => ({
  getState: vi.fn().mockResolvedValue(makeGetStateResponse()),
  saveState: vi.fn().mockResolvedValue(makeSaveStateResponse()),
  applyTemplate: vi.fn().mockResolvedValue(makeApplyTemplateResponse()),
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('ServerStateController', () => {
  let controller: ServerStateController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new ServerStateController(service as never);
  });

  // ─── getState ─────────────────────────────────────────────────────────────

  describe('getState', () => {
    it('returns null state with source=default when no snapshot has been saved', async () => {
      const result = await controller.getState('test-server');
      expect(result.state).toBeNull();
      expect(result.updated_at).toBeNull();
      expect(result.source).toBe('default');
    });

    it('calls service.getState with the correct serverLogin', async () => {
      await controller.getState('my-server');
      expect(service.getState).toHaveBeenCalledWith('my-server');
    });

    it('returns saved state with source=saved when one exists', async () => {
      const snapshot = makeSnapshot();
      service.getState.mockResolvedValue(
        makeGetStateResponse(
          snapshot as unknown as Record<string, unknown>,
          '2026-03-06T00:00:00.000Z',
          'saved',
        ),
      );

      const result = await controller.getState('test-server');
      expect(result.state).not.toBeNull();
      expect(result.updated_at).toBe('2026-03-06T00:00:00.000Z');
      expect(result.source).toBe('saved');
    });

    it('returns template config with source=template when no saved state and template is linked', async () => {
      const templateState = {
        state_version: '1.0',
        captured_at: 1741276800,
        admin: { current_best_of: 5 },
        veto_draft: { session: null, matchmaking_ready_armed: false, votes: {} },
      };
      service.getState.mockResolvedValue(
        makeGetStateResponse(
          templateState as Record<string, unknown>,
          null,
          'template',
        ),
      );

      const result = await controller.getState('test-server');
      expect(result.state).not.toBeNull();
      expect(result.updated_at).toBeNull();
      expect(result.source).toBe('template');
    });

    it('returns source=saved even when template is linked (saved takes priority)', async () => {
      const snapshot = makeSnapshot();
      service.getState.mockResolvedValue(
        makeGetStateResponse(
          snapshot as unknown as Record<string, unknown>,
          '2026-03-06T12:00:00.000Z',
          'saved',
        ),
      );

      const result = await controller.getState('test-server');
      expect(result.source).toBe('saved');
      expect(result.updated_at).toBe('2026-03-06T12:00:00.000Z');
    });

    it('propagates NotFoundException from service (unknown server)', async () => {
      service.getState.mockRejectedValue(new NotFoundException('Server not found'));
      await expect(controller.getState('nonexistent-server')).rejects.toThrow(NotFoundException);
    });
  });

  // ─── saveState ────────────────────────────────────────────────────────────

  describe('saveState', () => {
    it('calls service.saveState with serverLogin, snapshot, and extracted bearer token', async () => {
      const snapshot = makeSnapshot();
      await controller.saveState('test-server', 'Bearer my-token', snapshot);

      expect(service.saveState).toHaveBeenCalledWith('test-server', snapshot, 'my-token');
    });

    it('passes raw value when Authorization header lacks "Bearer " prefix', async () => {
      const snapshot = makeSnapshot();
      await controller.saveState('test-server', 'raw-token', snapshot);

      expect(service.saveState).toHaveBeenCalledWith('test-server', snapshot, 'raw-token');
    });

    it('passes undefined when Authorization header is absent', async () => {
      const snapshot = makeSnapshot();
      await controller.saveState('test-server', undefined, snapshot);

      expect(service.saveState).toHaveBeenCalledWith('test-server', snapshot, undefined);
    });

    it('returns saved: true with updated_at on success', async () => {
      const snapshot = makeSnapshot();
      const result = await controller.saveState('test-server', 'Bearer my-token', snapshot);

      expect(result.saved).toBe(true);
      expect(result.updated_at).toBe('2026-03-06T00:00:00.000Z');
    });

    it('propagates ForbiddenException when token is invalid', async () => {
      service.saveState.mockRejectedValue(new ForbiddenException('Invalid or missing link bearer token.'));
      const snapshot = makeSnapshot();

      await expect(
        controller.saveState('test-server', 'Bearer wrong-token', snapshot),
      ).rejects.toThrow(ForbiddenException);
    });

    it('propagates NotFoundException when server is not found', async () => {
      service.saveState.mockRejectedValue(new NotFoundException('Server not found'));
      const snapshot = makeSnapshot();

      await expect(
        controller.saveState('nonexistent-server', 'Bearer any-token', snapshot),
      ).rejects.toThrow(NotFoundException);
    });

    it('overwrites previously saved state (upsert semantics via service)', async () => {
      const updatedSnapshot = makeSnapshot({ state_version: '1.0', captured_at: 9999999 });
      service.saveState.mockResolvedValue(makeSaveStateResponse('2026-03-07T00:00:00.000Z'));

      const result = await controller.saveState('test-server', 'Bearer my-token', updatedSnapshot);
      expect(result.saved).toBe(true);
      expect(result.updated_at).toBe('2026-03-07T00:00:00.000Z');
    });
  });

  // ─── applyTemplate ────────────────────────────────────────────────────────

  describe('applyTemplate', () => {
    it('calls service.applyTemplate with serverLogin', async () => {
      await controller.applyTemplate('test-server');
      expect(service.applyTemplate).toHaveBeenCalledWith('test-server');
    });

    it('returns applied: true with template info', async () => {
      const result = await controller.applyTemplate('test-server');
      expect(result.applied).toBe(true);
      expect(result.template_id).toBe('tpl-uuid-1');
      expect(result.template_name).toBe('Elite Standard');
      expect(result.updated_at).toBe('2026-03-06T00:00:00.000Z');
    });

    it('propagates BadRequestException when server has no linked template', async () => {
      service.applyTemplate.mockRejectedValue(
        new BadRequestException('Server has no linked config template.'),
      );
      await expect(controller.applyTemplate('test-server')).rejects.toThrow(BadRequestException);
    });

    it('propagates NotFoundException when server is not found', async () => {
      service.applyTemplate.mockRejectedValue(new NotFoundException('Server not found'));
      await expect(controller.applyTemplate('nonexistent')).rejects.toThrow(NotFoundException);
    });
  });
});
