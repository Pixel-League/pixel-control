import { Injectable } from '@nestjs/common';

import { AdminProxyService } from '../admin-proxy/admin-proxy.service';
import { AdminActionResponse } from '../admin-proxy/dto/admin-action.dto';

/**
 * Service for player management admin commands (P4.6--P4.8).
 * Delegates to AdminProxyService for socket communication and auth injection.
 */
@Injectable()
export class AdminPlayersService {
  constructor(private readonly adminProxy: AdminProxyService) {}

  forceTeam(
    serverLogin: string,
    playerLogin: string,
    team: string,
  ): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'player.force_team', {
      target_login: playerLogin,
      team,
    });
  }

  forcePlay(serverLogin: string, playerLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'player.force_play', {
      target_login: playerLogin,
    });
  }

  forceSpec(serverLogin: string, playerLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'player.force_spec', {
      target_login: playerLogin,
    });
  }
}
