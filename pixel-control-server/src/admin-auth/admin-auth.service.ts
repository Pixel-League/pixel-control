import { Injectable } from '@nestjs/common';

import { AdminProxyService } from '../admin-proxy/admin-proxy.service';
import { AdminActionResponse } from '../admin-proxy/dto/admin-action.dto';

/**
 * Service for auth management admin commands (P5.1--P5.2).
 * Delegates to AdminProxyService for socket communication and auth injection.
 */
@Injectable()
export class AdminAuthService {
  constructor(private readonly adminProxy: AdminProxyService) {}

  grantAuth(
    serverLogin: string,
    playerLogin: string,
    authLevel: string,
  ): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'auth.grant', {
      target_login: playerLogin,
      auth_level: authLevel,
    });
  }

  revokeAuth(serverLogin: string, playerLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'auth.revoke', {
      target_login: playerLogin,
    });
  }
}
