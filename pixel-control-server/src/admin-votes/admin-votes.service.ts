import { Injectable } from '@nestjs/common';

import { AdminProxyService } from '../admin-proxy/admin-proxy.service';
import { AdminActionResponse } from '../admin-proxy/dto/admin-action.dto';

/**
 * Service for vote management admin commands (P5.10--P5.14).
 * Delegates to AdminProxyService for socket communication and auth injection.
 */
@Injectable()
export class AdminVotesService {
  constructor(private readonly adminProxy: AdminProxyService) {}

  cancelVote(serverLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'vote.cancel');
  }

  setVoteRatio(serverLogin: string, command: string, ratio: number): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'vote.set_ratio', { command, ratio });
  }

  startCustomVote(serverLogin: string, voteIndex: number): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'vote.custom_start', {
      vote_index: voteIndex,
    });
  }

  getVotePolicy(serverLogin: string): Promise<AdminActionResponse> {
    return this.adminProxy.queryAction(serverLogin, 'vote.policy.get');
  }

  setVotePolicy(serverLogin: string, mode: string): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'vote.policy.set', { mode });
  }
}
