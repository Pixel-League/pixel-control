import { Injectable } from '@nestjs/common';

import { AdminProxyService } from '../admin-proxy/admin-proxy.service';
import { AdminActionResponse } from '../admin-proxy/dto/admin-action.dto';

/**
 * Service for warmup and pause admin commands (P3.7--P3.10).
 * Delegates to AdminProxyService for socket communication and auth injection.
 */
@Injectable()
export class AdminWarmupPauseService {
  constructor(private readonly adminProxy: AdminProxyService) {}

  extendWarmup(serverLogin: string, seconds: number): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'warmup.extend', { seconds });
  }

  endWarmup(serverLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'warmup.end');
  }

  startPause(serverLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'pause.start');
  }

  endPause(serverLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'pause.end');
  }
}
