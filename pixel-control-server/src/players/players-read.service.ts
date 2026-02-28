import { Injectable, Logger, NotFoundException } from '@nestjs/common';

import { PaginatedResponse, paginate } from '../common/dto/read-response.dto';
import { ServerResolverService } from '../common/services/server-resolver.service';
import { PrismaService } from '../prisma/prisma.service';

export interface PlayerState {
  login: string;
  nickname: string | null;
  team_id: number | null;
  is_spectator: boolean;
  is_connected: boolean;
  has_joined_game: boolean;
  auth_level: number | null;
  auth_name: string | null;
  connectivity_state: string | null;
  readiness_state: string | null;
  eligibility_state: string | null;
  last_updated: string;
}

export interface PlayerDetailState extends PlayerState {
  permission_signals: Record<string, unknown> | null;
  roster_state: Record<string, unknown> | null;
  reconnect_continuity: Record<string, unknown> | null;
  side_change: Record<string, unknown> | null;
  constraint_signals: Record<string, unknown> | null;
  last_event_id: string | null;
}

interface RawPlayerPayload {
  event_kind?: string;
  player?: {
    login?: string;
    nickname?: string;
    team_id?: number;
    is_spectator?: boolean;
    is_connected?: boolean;
    has_joined_game?: boolean;
    auth_level?: number;
    auth_name?: string;
  };
  state_delta?: {
    connectivity_state?: string;
    readiness_state?: string;
    eligibility_state?: string;
  };
  permission_signals?: Record<string, unknown>;
  roster_state?: Record<string, unknown>;
  reconnect_continuity?: Record<string, unknown>;
  side_change?: Record<string, unknown>;
  constraint_signals?: Record<string, unknown>;
}

@Injectable()
export class PlayersReadService {
  private readonly logger = new Logger(PlayersReadService.name);

  constructor(
    private readonly serverResolver: ServerResolverService,
    private readonly prisma: PrismaService,
  ) {}

  async getPlayers(
    serverLogin: string,
    limit: number,
    offset: number,
  ): Promise<PaginatedResponse<PlayerState>> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    // Fetch all player events ordered newest-first
    const events = await this.prisma.event.findMany({
      where: { serverId: server.id, eventCategory: 'player' },
      orderBy: { sourceTime: 'desc' },
    });

    // Build de-duplicated player map: newest event per login wins
    const playerMap = new Map<string, PlayerState>();

    for (const event of events) {
      const payload = event.payload as RawPlayerPayload;
      const login = payload?.player?.login;

      if (!login || playerMap.has(login)) {
        continue;
      }

      const isDisconnected = payload.event_kind === 'player.disconnect';

      playerMap.set(login, {
        login,
        nickname: payload.player?.nickname ?? null,
        team_id: payload.player?.team_id ?? null,
        is_spectator: payload.player?.is_spectator ?? false,
        is_connected: !isDisconnected && (payload.player?.is_connected ?? true),
        has_joined_game: payload.player?.has_joined_game ?? false,
        auth_level: payload.player?.auth_level ?? null,
        auth_name: payload.player?.auth_name ?? null,
        connectivity_state: payload.state_delta?.connectivity_state ?? null,
        readiness_state: payload.state_delta?.readiness_state ?? null,
        eligibility_state: payload.state_delta?.eligibility_state ?? null,
        last_updated: new Date(Number(event.sourceTime)).toISOString(),
      });
    }

    const players = Array.from(playerMap.values());
    return paginate(players, limit, offset);
  }

  async getPlayer(
    serverLogin: string,
    playerLogin: string,
  ): Promise<PlayerDetailState> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    // Fetch the latest player event for this login
    // Since Prisma doesn't natively filter by JSON fields, we fetch recent events and filter in TS
    const events = await this.prisma.event.findMany({
      where: { serverId: server.id, eventCategory: 'player' },
      orderBy: { sourceTime: 'desc' },
      take: 500,
    });

    const matchingEvent = events.find((event) => {
      const payload = event.payload as RawPlayerPayload;
      return payload?.player?.login === playerLogin;
    });

    if (!matchingEvent) {
      throw new NotFoundException(
        `Player '${playerLogin}' not found on server '${serverLogin}'`,
      );
    }

    const payload = matchingEvent.payload as RawPlayerPayload;
    const isDisconnected = payload.event_kind === 'player.disconnect';

    return {
      login: playerLogin,
      nickname: payload.player?.nickname ?? null,
      team_id: payload.player?.team_id ?? null,
      is_spectator: payload.player?.is_spectator ?? false,
      is_connected: !isDisconnected && (payload.player?.is_connected ?? true),
      has_joined_game: payload.player?.has_joined_game ?? false,
      auth_level: payload.player?.auth_level ?? null,
      auth_name: payload.player?.auth_name ?? null,
      connectivity_state: payload.state_delta?.connectivity_state ?? null,
      readiness_state: payload.state_delta?.readiness_state ?? null,
      eligibility_state: payload.state_delta?.eligibility_state ?? null,
      permission_signals: payload.permission_signals ?? null,
      roster_state: payload.roster_state ?? null,
      reconnect_continuity: payload.reconnect_continuity ?? null,
      side_change: payload.side_change ?? null,
      constraint_signals: payload.constraint_signals ?? null,
      last_event_id: matchingEvent.eventId ?? null,
      last_updated: new Date(Number(matchingEvent.sourceTime)).toISOString(),
    };
  }
}
