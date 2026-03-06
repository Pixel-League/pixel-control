import { beforeEach, describe, expect, it, vi } from 'vitest';

import { AdminPlayersController } from './admin-players.controller';

// ---------------------------------------------------------------------------
// Factory helpers
// ---------------------------------------------------------------------------

const makeActionResponse = (overrides: Partial<{ action_name: string; code: string }> = {}) => ({
  action_name: overrides.action_name ?? 'player.force_team',
  success: true,
  code: overrides.code ?? 'player_team_forced',
  message: 'OK',
  details: undefined,
});

const makeServiceStub = () => ({
  forceTeam: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'player.force_team', code: 'player_team_forced' })),
  forcePlay: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'player.force_play', code: 'player_forced_play' })),
  forceSpec: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'player.force_spec', code: 'player_forced_spec' })),
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('AdminPlayersController', () => {
  let controller: AdminPlayersController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new AdminPlayersController(service as never);
  });

  describe('forceTeam', () => {
    it('calls service.forceTeam with serverLogin, playerLogin, and team', async () => {
      await controller.forceTeam('test-server', 'player.login', { team: 'team_a' });
      expect(service.forceTeam).toHaveBeenCalledWith('test-server', 'player.login', 'team_a');
    });

    it('returns the action response', async () => {
      const result = await controller.forceTeam('test-server', 'player.login', { team: 'team_b' });
      expect(result.action_name).toBe('player.force_team');
      expect(result.success).toBe(true);
      expect(result.code).toBe('player_team_forced');
    });
  });

  describe('forcePlay', () => {
    it('calls service.forcePlay with serverLogin and playerLogin', async () => {
      await controller.forcePlay('test-server', 'player.login');
      expect(service.forcePlay).toHaveBeenCalledWith('test-server', 'player.login');
    });

    it('returns the action response', async () => {
      const result = await controller.forcePlay('test-server', 'player.login');
      expect(result.action_name).toBe('player.force_play');
      expect(result.code).toBe('player_forced_play');
    });
  });

  describe('forceSpec', () => {
    it('calls service.forceSpec with serverLogin and playerLogin', async () => {
      await controller.forceSpec('test-server', 'player.login');
      expect(service.forceSpec).toHaveBeenCalledWith('test-server', 'player.login');
    });

    it('returns the action response', async () => {
      const result = await controller.forceSpec('test-server', 'player.login');
      expect(result.action_name).toBe('player.force_spec');
      expect(result.code).toBe('player_forced_spec');
    });
  });
});
