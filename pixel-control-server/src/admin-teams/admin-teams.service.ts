import { Injectable } from '@nestjs/common';

import { AdminProxyService } from '../admin-proxy/admin-proxy.service';
import {
  AdminActionResponse,
  TeamPolicyDto,
  TeamRosterAssignDto,
} from '../admin-proxy/dto/admin-action.dto';

/**
 * Service for team control admin commands (P4.9--P4.13).
 * Delegates to AdminProxyService for socket communication and auth injection.
 */
@Injectable()
export class AdminTeamsService {
  constructor(private readonly adminProxy: AdminProxyService) {}

  setPolicy(serverLogin: string, dto: TeamPolicyDto): Promise<AdminActionResponse> {
    const params: Record<string, unknown> = { enabled: dto.enabled };
    if (dto.switch_lock !== undefined) {
      params['switch_lock'] = dto.switch_lock;
    }
    return this.adminProxy.executeAction(serverLogin, 'team.policy.set', params);
  }

  getPolicy(serverLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.queryAction(serverLogin, 'team.policy.get');
  }

  assignRoster(serverLogin: string, dto: TeamRosterAssignDto): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'team.roster.assign', {
      target_login: dto.target_login,
      team: dto.team,
    });
  }

  unassignRoster(serverLogin: string, playerLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'team.roster.unassign', {
      target_login: playerLogin,
    });
  }

  listRoster(serverLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.queryAction(serverLogin, 'team.roster.list');
  }
}
