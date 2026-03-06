import { Injectable } from '@nestjs/common';

import { AdminProxyService } from '../admin-proxy/admin-proxy.service';
import { AdminActionResponse } from '../admin-proxy/dto/admin-action.dto';

/**
 * Service for whitelist management admin commands (P5.3--P5.9).
 * Delegates to AdminProxyService for socket communication and auth injection.
 */
@Injectable()
export class AdminWhitelistService {
  constructor(private readonly adminProxy: AdminProxyService) {}

  enableWhitelist(serverLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'whitelist.enable');
  }

  disableWhitelist(serverLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'whitelist.disable');
  }

  addToWhitelist(serverLogin: string, targetLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'whitelist.add', {
      target_login: targetLogin,
    });
  }

  removeFromWhitelist(serverLogin: string, login: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'whitelist.remove', {
      target_login: login,
    });
  }

  listWhitelist(serverLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.queryAction(serverLogin, 'whitelist.list');
  }

  cleanWhitelist(serverLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'whitelist.clean');
  }

  syncWhitelist(serverLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'whitelist.sync');
  }
}
