import { beforeEach, describe, expect, it, vi } from 'vitest';

import { AdminVotesController } from './admin-votes.controller';

// ---------------------------------------------------------------------------
// Factory helpers
// ---------------------------------------------------------------------------

const makeActionResponse = (overrides: Partial<{ action_name: string; code: string }> = {}) => ({
  action_name: overrides.action_name ?? 'vote.cancel',
  success: true,
  code: overrides.code ?? 'vote_cancelled',
  message: 'OK',
  details: undefined,
});

const makeServiceStub = () => ({
  cancelVote: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'vote.cancel', code: 'vote_cancelled' })),
  setVoteRatio: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'vote.set_ratio', code: 'vote_ratio_set' })),
  startCustomVote: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'vote.custom_start', code: 'custom_vote_started' })),
  getVotePolicy: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'vote.policy.get', code: 'vote_policy_returned' })),
  setVotePolicy: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'vote.policy.set', code: 'vote_policy_set' })),
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('AdminVotesController', () => {
  let controller: AdminVotesController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new AdminVotesController(service as never);
  });

  describe('cancelVote', () => {
    it('calls service.cancelVote with serverLogin', async () => {
      await controller.cancelVote('test-server');
      expect(service.cancelVote).toHaveBeenCalledWith('test-server');
    });

    it('returns the action response', async () => {
      const result = await controller.cancelVote('test-server');
      expect(result.action_name).toBe('vote.cancel');
      expect(result.success).toBe(true);
    });
  });

  describe('setVoteRatio', () => {
    it('calls service.setVoteRatio with serverLogin, command, and ratio', async () => {
      await controller.setVoteRatio('test-server', { command: 'skip', ratio: 0.5 });
      expect(service.setVoteRatio).toHaveBeenCalledWith('test-server', 'skip', 0.5);
    });

    it('returns the action response', async () => {
      const result = await controller.setVoteRatio('test-server', { command: 'skip', ratio: 0.5 });
      expect(result.code).toBe('vote_ratio_set');
    });
  });

  describe('startCustomVote', () => {
    it('calls service.startCustomVote with serverLogin and vote_index', async () => {
      await controller.startCustomVote('test-server', { vote_index: 1 });
      expect(service.startCustomVote).toHaveBeenCalledWith('test-server', 1);
    });

    it('returns the action response', async () => {
      const result = await controller.startCustomVote('test-server', { vote_index: 2 });
      expect(result.code).toBe('custom_vote_started');
    });
  });

  describe('getVotePolicy', () => {
    it('calls service.getVotePolicy with serverLogin', async () => {
      await controller.getVotePolicy('test-server');
      expect(service.getVotePolicy).toHaveBeenCalledWith('test-server');
    });

    it('returns the action response', async () => {
      const result = await controller.getVotePolicy('test-server');
      expect(result.action_name).toBe('vote.policy.get');
    });
  });

  describe('setVotePolicy', () => {
    it('calls service.setVotePolicy with serverLogin and mode', async () => {
      await controller.setVotePolicy('test-server', { mode: 'strict' });
      expect(service.setVotePolicy).toHaveBeenCalledWith('test-server', 'strict');
    });

    it('returns the action response', async () => {
      const result = await controller.setVotePolicy('test-server', { mode: 'lenient' });
      expect(result.code).toBe('vote_policy_set');
    });
  });

  describe('error propagation', () => {
    it('propagates service rejection for unknown server', async () => {
      service.cancelVote.mockRejectedValue(new Error('server not found'));
      await expect(controller.cancelVote('nonexistent-server')).rejects.toThrow('server not found');
    });
  });
});
