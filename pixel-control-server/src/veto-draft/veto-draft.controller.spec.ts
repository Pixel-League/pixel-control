import { beforeEach, describe, expect, it, vi } from 'vitest';

import { VetoDraftController } from './veto-draft.controller';

// ---------------------------------------------------------------------------
// Factory helpers
// ---------------------------------------------------------------------------

const makeCommandResponse = (overrides: Partial<{ code: string }> = {}) => ({
  success: true,
  code: overrides.code ?? 'ok',
  message: 'OK',
  details: undefined,
});

const makeStatusResponse = () => ({
  success: true,
  code: 'status_retrieved',
  message: 'OK',
  status: { active: false, mode: null, session: { status: 'idle' } },
});

const makeServiceStub = () => ({
  getStatus: vi.fn().mockResolvedValue(makeStatusResponse()),
  armReady: vi.fn().mockResolvedValue(makeCommandResponse({ code: 'matchmaking_ready_armed' })),
  startSession: vi.fn().mockResolvedValue(makeCommandResponse({ code: 'session_started' })),
  submitAction: vi.fn().mockResolvedValue(makeCommandResponse({ code: 'action_submitted' })),
  cancelSession: vi.fn().mockResolvedValue(makeCommandResponse({ code: 'session_cancelled' })),
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('VetoDraftController', () => {
  let controller: VetoDraftController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new VetoDraftController(service as never);
  });

  describe('getStatus', () => {
    it('calls service.getStatus with the serverLogin', async () => {
      await controller.getStatus('test-server');
      expect(service.getStatus).toHaveBeenCalledWith('test-server');
    });

    it('returns the status response', async () => {
      const result = await controller.getStatus('test-server');
      expect(result.success).toBe(true);
      expect(result.code).toBe('status_retrieved');
    });
  });

  describe('armReady', () => {
    it('calls service.armReady with the serverLogin', async () => {
      await controller.armReady('test-server');
      expect(service.armReady).toHaveBeenCalledWith('test-server');
    });

    it('returns the command response', async () => {
      const result = await controller.armReady('test-server');
      expect(result.success).toBe(true);
      expect(result.code).toBe('matchmaking_ready_armed');
    });
  });

  describe('startSession', () => {
    it('calls service.startSession with serverLogin and dto', async () => {
      const dto = { mode: 'tournament_draft', captain_a: 'cap_a', captain_b: 'cap_b' };
      await controller.startSession('test-server', dto as never);
      expect(service.startSession).toHaveBeenCalledWith('test-server', dto);
    });

    it('returns the command response', async () => {
      const result = await controller.startSession('test-server', { mode: 'matchmaking_vote' });
      expect(result.code).toBe('session_started');
    });
  });

  describe('submitAction', () => {
    it('calls service.submitAction with serverLogin and dto', async () => {
      const dto = { actor_login: 'player.one', map: 'map-uid-001', operation: 'ban' };
      await controller.submitAction('test-server', dto as never);
      expect(service.submitAction).toHaveBeenCalledWith('test-server', dto);
    });
  });

  describe('cancelSession', () => {
    it('calls service.cancelSession with serverLogin and dto', async () => {
      const dto = { reason: 'Admin cancelled' };
      await controller.cancelSession('test-server', dto);
      expect(service.cancelSession).toHaveBeenCalledWith('test-server', dto);
    });

    it('calls service.cancelSession with undefined when no body provided', async () => {
      await controller.cancelSession('test-server', undefined);
      expect(service.cancelSession).toHaveBeenCalledWith('test-server', undefined);
    });

    it('returns the command response', async () => {
      const result = await controller.cancelSession('test-server');
      expect(result.success).toBe(true);
      expect(result.code).toBe('session_cancelled');
    });
  });
});
