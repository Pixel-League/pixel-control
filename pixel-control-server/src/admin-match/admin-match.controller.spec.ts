import { ServiceUnavailableException } from '@nestjs/common';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { AdminMatchController } from './admin-match.controller';

// ---------------------------------------------------------------------------
// Factory helpers
// ---------------------------------------------------------------------------

const makeActionResponse = (
  actionName: string,
  code: string,
  details?: Record<string, unknown>,
) => ({
  action_name: actionName,
  success: true,
  code,
  message: 'OK',
  details,
});

const makeServiceStub = () => ({
  setBestOf: vi.fn().mockResolvedValue(makeActionResponse('match.bo.set', 'match_bo_set')),
  getBestOf: vi.fn().mockResolvedValue(makeActionResponse('match.bo.get', 'match_bo_retrieved', { best_of: 3 })),
  setMapsScore: vi.fn().mockResolvedValue(makeActionResponse('match.maps.set', 'match_maps_set')),
  getMapsScore: vi.fn().mockResolvedValue(makeActionResponse('match.maps.get', 'match_maps_retrieved', { team_a_maps: 1, team_b_maps: 0 })),
  setRoundScore: vi.fn().mockResolvedValue(makeActionResponse('match.score.set', 'match_score_set')),
  getRoundScore: vi.fn().mockResolvedValue(makeActionResponse('match.score.get', 'match_score_retrieved', { team_a_score: 50, team_b_score: 30 })),
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('AdminMatchController', () => {
  let controller: AdminMatchController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new AdminMatchController(service as never);
  });

  // ─── setBestOf ───────────────────────────────────────────────────────────────

  describe('setBestOf', () => {
    it('calls service.setBestOf with serverLogin and best_of', async () => {
      await controller.setBestOf('test-server', { best_of: 3 });
      expect(service.setBestOf).toHaveBeenCalledWith('test-server', 3);
    });

    it('returns the service response', async () => {
      const result = await controller.setBestOf('test-server', { best_of: 5 });
      expect(result.action_name).toBe('match.bo.set');
      expect(result.success).toBe(true);
    });
  });

  // ─── getBestOf ───────────────────────────────────────────────────────────────

  describe('getBestOf', () => {
    it('calls service.getBestOf with serverLogin', async () => {
      await controller.getBestOf('test-server');
      expect(service.getBestOf).toHaveBeenCalledWith('test-server');
    });

    it('returns details containing best_of', async () => {
      const result = await controller.getBestOf('test-server');
      expect(result.details).toEqual({ best_of: 3 });
    });

    it('propagates ServiceUnavailableException from service', async () => {
      service.getBestOf.mockRejectedValue(new ServiceUnavailableException('Socket unavailable'));
      await expect(controller.getBestOf('test-server')).rejects.toThrow(ServiceUnavailableException);
    });
  });

  // ─── setMapsScore ─────────────────────────────────────────────────────────────

  describe('setMapsScore', () => {
    it('calls service.setMapsScore with serverLogin, target_team, and maps_score', async () => {
      await controller.setMapsScore('test-server', { target_team: 'team_a', maps_score: 1 });
      expect(service.setMapsScore).toHaveBeenCalledWith('test-server', 'team_a', 1);
    });
  });

  // ─── getMapsScore ─────────────────────────────────────────────────────────────

  describe('getMapsScore', () => {
    it('calls service.getMapsScore with serverLogin', async () => {
      await controller.getMapsScore('test-server');
      expect(service.getMapsScore).toHaveBeenCalledWith('test-server');
    });

    it('returns details with team maps scores', async () => {
      const result = await controller.getMapsScore('test-server');
      expect(result.details).toEqual({ team_a_maps: 1, team_b_maps: 0 });
    });
  });

  // ─── setRoundScore ────────────────────────────────────────────────────────────

  describe('setRoundScore', () => {
    it('calls service.setRoundScore with serverLogin, target_team, and score', async () => {
      await controller.setRoundScore('test-server', { target_team: 'team_b', score: 100 });
      expect(service.setRoundScore).toHaveBeenCalledWith('test-server', 'team_b', 100);
    });
  });

  // ─── getRoundScore ────────────────────────────────────────────────────────────

  describe('getRoundScore', () => {
    it('calls service.getRoundScore with serverLogin', async () => {
      await controller.getRoundScore('test-server');
      expect(service.getRoundScore).toHaveBeenCalledWith('test-server');
    });

    it('returns details with team round scores', async () => {
      const result = await controller.getRoundScore('test-server');
      expect(result.details).toEqual({ team_a_score: 50, team_b_score: 30 });
    });

    it('propagates ServiceUnavailableException from service', async () => {
      service.getRoundScore.mockRejectedValue(new ServiceUnavailableException('Socket unavailable'));
      await expect(controller.getRoundScore('test-server')).rejects.toThrow(ServiceUnavailableException);
    });
  });
});
