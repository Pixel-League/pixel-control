import { Injectable } from '@nestjs/common';

import { AdminProxyService } from '../admin-proxy/admin-proxy.service';
import { AdminActionResponse } from '../admin-proxy/dto/admin-action.dto';

/**
 * Service for map management admin commands (P3.1--P3.6).
 * Delegates to AdminProxyService for socket communication and auth injection.
 */
@Injectable()
export class AdminMapsService {
  constructor(private readonly adminProxy: AdminProxyService) {}

  skipMap(serverLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'map.skip');
  }

  restartMap(serverLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'map.restart');
  }

  jumpToMap(serverLogin: string, mapUid: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'map.jump', { map_uid: mapUid });
  }

  queueMap(serverLogin: string, mapUid: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'map.queue', { map_uid: mapUid });
  }

  addMap(serverLogin: string, mxId: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'map.add', { mx_id: mxId });
  }

  removeMap(serverLogin: string, mapUid: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'map.remove', { map_uid: mapUid });
  }
}
