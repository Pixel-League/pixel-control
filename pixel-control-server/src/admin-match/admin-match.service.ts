import { Injectable, ServiceUnavailableException } from '@nestjs/common';

import { AdminProxyService } from '../admin-proxy/admin-proxy.service';
import { AdminActionResponse } from '../admin-proxy/dto/admin-action.dto';

/**
 * Service for match/series configuration admin commands (P3.11--P3.16).
 * Delegates to AdminProxyService for socket communication and auth injection.
 */
@Injectable()
export class AdminMatchService {
  constructor(private readonly adminProxy: AdminProxyService) {}

  // ─── Write endpoints (P3.11, P3.13, P3.15) ───────────────────────────────────

  setBestOf(serverLogin: string, bestOf: number): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'match.bo.set', { best_of: bestOf });
  }

  setMapsScore(
    serverLogin: string,
    targetTeam: string,
    mapsScore: number,
  ): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'match.maps.set', {
      target_team: targetTeam,
      maps_score: mapsScore,
    });
  }

  setRoundScore(
    serverLogin: string,
    targetTeam: string,
    score: number,
  ): Promise<AdminActionResponse> {
    return this.adminProxy.executeAction(serverLogin, 'match.score.set', {
      target_team: targetTeam,
      score,
    });
  }

  // ─── Read endpoints (P3.12, P3.14, P3.16) ────────────────────────────────────

  async getBestOf(serverLogin: string): Promise<AdminActionResponse> {
    return this.queryWithFallback(serverLogin, 'match.bo.get');
  }

  async getMapsScore(serverLogin: string): Promise<AdminActionResponse> {
    return this.queryWithFallback(serverLogin, 'match.maps.get');
  }

  async getRoundScore(serverLogin: string): Promise<AdminActionResponse> {
    return this.queryWithFallback(serverLogin, 'match.score.get');
  }

  /**
   * Queries the socket for live match state.
   * If the socket is unreachable (BadGatewayException), returns a 503 ServiceUnavailableException
   * with a descriptive message about the socket being unavailable.
   */
  private async queryWithFallback(
    serverLogin: string,
    actionName: string,
  ): Promise<AdminActionResponse> {
    try {
      return await this.adminProxy.queryAction(serverLogin, actionName);
    } catch (err) {
      // If the socket is unreachable (502), escalate to 503 with a descriptive message.
      const statusCode = (err as { status?: number })?.status;
      if (statusCode === 502) {
        throw new ServiceUnavailableException(
          `ManiaControl socket unavailable. Cannot query '${actionName}' live data. ` +
            'Ensure the ManiaControl server is running with MC_SOCKET_HOST/MC_SOCKET_PORT/MC_SOCKET_PASSWORD configured.',
        );
      }
      throw err;
    }
  }
}
