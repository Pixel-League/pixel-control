import { beforeEach, describe, expect, it, vi } from 'vitest';

import { AdminTeamsController } from './admin-teams.controller';

// ---------------------------------------------------------------------------
// Factory helpers
// ---------------------------------------------------------------------------

const makeActionResponse = (overrides: Partial<{ action_name: string; code: string }> = {}) => ({
  action_name: overrides.action_name ?? 'team.policy.set',
  success: true,
  code: overrides.code ?? 'team_policy_set',
  message: 'OK',
  details: undefined,
});

const makeServiceStub = () => ({
  setPolicy: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'team.policy.set', code: 'team_policy_set' })),
  getPolicy: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'team.policy.get', code: 'team_policy_retrieved' })),
  assignRoster: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'team.roster.assign', code: 'team_roster_assigned' })),
  unassignRoster: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'team.roster.unassign', code: 'team_roster_unassigned' })),
  listRoster: vi.fn().mockResolvedValue(makeActionResponse({ action_name: 'team.roster.list', code: 'team_roster_retrieved' })),
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('AdminTeamsController', () => {
  let controller: AdminTeamsController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new AdminTeamsController(service as never);
  });

  describe('setPolicy', () => {
    it('calls service.setPolicy with serverLogin and dto', async () => {
      const dto = { enabled: true, switch_lock: false };
      await controller.setPolicy('test-server', dto);
      expect(service.setPolicy).toHaveBeenCalledWith('test-server', dto);
    });

    it('returns the action response', async () => {
      const result = await controller.setPolicy('test-server', { enabled: true });
      expect(result.action_name).toBe('team.policy.set');
      expect(result.success).toBe(true);
      expect(result.code).toBe('team_policy_set');
    });
  });

  describe('getPolicy', () => {
    it('calls service.getPolicy with the serverLogin', async () => {
      await controller.getPolicy('test-server');
      expect(service.getPolicy).toHaveBeenCalledWith('test-server');
    });

    it('returns the action response', async () => {
      const result = await controller.getPolicy('test-server');
      expect(result.action_name).toBe('team.policy.get');
      expect(result.code).toBe('team_policy_retrieved');
    });
  });

  describe('assignRoster', () => {
    it('calls service.assignRoster with serverLogin and dto', async () => {
      const dto = { target_login: 'player.one', team: 'team_a' };
      await controller.assignRoster('test-server', dto);
      expect(service.assignRoster).toHaveBeenCalledWith('test-server', dto);
    });

    it('returns the action response', async () => {
      const result = await controller.assignRoster('test-server', {
        target_login: 'player.one',
        team: 'team_b',
      });
      expect(result.action_name).toBe('team.roster.assign');
      expect(result.code).toBe('team_roster_assigned');
    });
  });

  describe('unassignRoster', () => {
    it('calls service.unassignRoster with serverLogin and login param', async () => {
      await controller.unassignRoster('test-server', 'player.one');
      expect(service.unassignRoster).toHaveBeenCalledWith('test-server', 'player.one');
    });

    it('returns the action response', async () => {
      const result = await controller.unassignRoster('test-server', 'player.one');
      expect(result.action_name).toBe('team.roster.unassign');
      expect(result.code).toBe('team_roster_unassigned');
    });
  });

  describe('listRoster', () => {
    it('calls service.listRoster with the serverLogin', async () => {
      await controller.listRoster('test-server');
      expect(service.listRoster).toHaveBeenCalledWith('test-server');
    });

    it('returns the action response', async () => {
      const result = await controller.listRoster('test-server');
      expect(result.action_name).toBe('team.roster.list');
      expect(result.code).toBe('team_roster_retrieved');
    });
  });
});
