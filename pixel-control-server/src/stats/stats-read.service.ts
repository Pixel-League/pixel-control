import { Injectable, Logger, NotFoundException } from '@nestjs/common';

import { PaginatedResponse, paginate } from '../common/dto/read-response.dto';
import { ServerResolverService } from '../common/services/server-resolver.service';
import { PrismaService } from '../prisma/prisma.service';

export interface PlayerCounters {
  login: string;
  kills: number;
  deaths: number;
  hits: number;
  shots: number;
  misses: number;
  rockets: number;
  lasers: number;
  accuracy: number;
}

export interface CombatSummaryResponse {
  server_login: string;
  combat_summary: {
    total_events: number;
    total_kills: number;
    total_deaths: number;
    total_hits: number;
    total_shots: number;
    total_accuracy: number;
    tracked_player_count: number;
    event_kinds: Record<string, number>;
  };
  time_range: {
    since: string | null;
    until: string | null;
    event_count: number;
  };
}

export interface PlayerCountersListResponse extends PaginatedResponse<PlayerCounters> {}

export interface PlayerCounterDetailResponse {
  login: string;
  counters: Omit<PlayerCounters, 'login'>;
  recent_events_count: number;
  last_updated: string;
}

export interface ScoresResponse {
  server_login: string;
  scores_section: string | null;
  scores_snapshot: Record<string, unknown> | null;
  scores_result: Record<string, unknown> | null;
  source_time: string | null;
  event_id: string | null;
  no_scores_available?: boolean;
}

interface RawCombatPayload {
  event_kind?: string;
  player_counters?: Record<string, {
    kills?: number;
    deaths?: number;
    hits?: number;
    shots?: number;
    misses?: number;
    rockets?: number;
    lasers?: number;
    accuracy?: number;
  }>;
  scores_section?: string;
  scores_snapshot?: Record<string, unknown>;
  scores_result?: Record<string, unknown>;
}

@Injectable()
export class StatsReadService {
  private readonly logger = new Logger(StatsReadService.name);

  constructor(
    private readonly serverResolver: ServerResolverService,
    private readonly prisma: PrismaService,
  ) {}

  async getCombatStats(
    serverLogin: string,
    since?: string,
    until?: string,
  ): Promise<CombatSummaryResponse> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    const sinceMs = since ? BigInt(new Date(since).getTime()) : undefined;
    const untilMs = until ? BigInt(new Date(until).getTime()) : undefined;

    const events = await this.prisma.event.findMany({
      where: {
        serverId: server.id,
        eventCategory: 'combat',
        ...(sinceMs !== undefined || untilMs !== undefined
          ? {
              sourceTime: {
                ...(sinceMs !== undefined ? { gte: sinceMs } : {}),
                ...(untilMs !== undefined ? { lte: untilMs } : {}),
              },
            }
          : {}),
      },
      orderBy: { sourceTime: 'desc' },
    });

    // Count event_kinds across all events
    const eventKinds: Record<string, number> = {};
    for (const event of events) {
      const payload = event.payload as RawCombatPayload;
      const kind = payload?.event_kind ?? 'unknown';
      eventKinds[kind] = (eventKinds[kind] ?? 0) + 1;
    }

    // Use the latest combat event's player_counters (counters are cumulative session totals)
    const latestWithCounters = events.find(
      (e) => (e.payload as RawCombatPayload)?.player_counters,
    );

    let totalKills = 0;
    let totalDeaths = 0;
    let totalHits = 0;
    let totalShots = 0;
    let trackedPlayerCount = 0;

    if (latestWithCounters) {
      const payload = latestWithCounters.payload as RawCombatPayload;
      const counters = payload.player_counters ?? {};
      trackedPlayerCount = Object.keys(counters).length;

      for (const player of Object.values(counters)) {
        totalKills += player.kills ?? 0;
        totalDeaths += player.deaths ?? 0;
        totalHits += player.hits ?? 0;
        totalShots += player.shots ?? 0;
      }
    }

    const totalAccuracy = totalShots > 0 ? totalHits / totalShots : 0;

    return {
      server_login: serverLogin,
      combat_summary: {
        total_events: events.length,
        total_kills: totalKills,
        total_deaths: totalDeaths,
        total_hits: totalHits,
        total_shots: totalShots,
        total_accuracy: totalAccuracy,
        tracked_player_count: trackedPlayerCount,
        event_kinds: eventKinds,
      },
      time_range: {
        since: since ?? null,
        until: until ?? null,
        event_count: events.length,
      },
    };
  }

  async getCombatPlayersCounters(
    serverLogin: string,
    limit: number,
    offset: number,
    since?: string,
    until?: string,
  ): Promise<PlayerCountersListResponse> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    const sinceMs = since ? BigInt(new Date(since).getTime()) : undefined;
    const untilMs = until ? BigInt(new Date(until).getTime()) : undefined;

    // Find the latest combat event with player_counters in scope
    const latestEvent = await this.prisma.event.findFirst({
      where: {
        serverId: server.id,
        eventCategory: 'combat',
        ...(sinceMs !== undefined || untilMs !== undefined
          ? {
              sourceTime: {
                ...(sinceMs !== undefined ? { gte: sinceMs } : {}),
                ...(untilMs !== undefined ? { lte: untilMs } : {}),
              },
            }
          : {}),
      },
      orderBy: { sourceTime: 'desc' },
    });

    if (!latestEvent) {
      return paginate([], limit, offset);
    }

    const payload = latestEvent.payload as RawCombatPayload;
    const counters = payload?.player_counters ?? {};

    const players: PlayerCounters[] = Object.entries(counters).map(
      ([login, c]) => ({
        login,
        kills: c.kills ?? 0,
        deaths: c.deaths ?? 0,
        hits: c.hits ?? 0,
        shots: c.shots ?? 0,
        misses: c.misses ?? 0,
        rockets: c.rockets ?? 0,
        lasers: c.lasers ?? 0,
        accuracy: c.accuracy ?? (c.shots ? (c.hits ?? 0) / c.shots : 0),
      }),
    );

    return paginate(players, limit, offset);
  }

  async getPlayerCombatCounters(
    serverLogin: string,
    playerLogin: string,
  ): Promise<PlayerCounterDetailResponse> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    // Fetch all combat events and find the latest one with this player's counters
    const events = await this.prisma.event.findMany({
      where: { serverId: server.id, eventCategory: 'combat' },
      orderBy: { sourceTime: 'desc' },
      take: 500,
    });

    const matchingEvent = events.find((event) => {
      const payload = event.payload as RawCombatPayload;
      return payload?.player_counters && playerLogin in payload.player_counters;
    });

    if (!matchingEvent) {
      throw new NotFoundException(
        `No combat data found for player '${playerLogin}' on server '${serverLogin}'`,
      );
    }

    const payload = matchingEvent.payload as RawCombatPayload;
    const c = payload.player_counters![playerLogin];

    return {
      login: playerLogin,
      counters: {
        kills: c.kills ?? 0,
        deaths: c.deaths ?? 0,
        hits: c.hits ?? 0,
        shots: c.shots ?? 0,
        misses: c.misses ?? 0,
        rockets: c.rockets ?? 0,
        lasers: c.lasers ?? 0,
        accuracy: c.accuracy ?? (c.shots ? (c.hits ?? 0) / c.shots : 0),
      },
      recent_events_count: events.length,
      last_updated: new Date(Number(matchingEvent.sourceTime)).toISOString(),
    };
  }

  async getLatestScores(serverLogin: string): Promise<ScoresResponse> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    // Find the latest combat event with event_kind = 'scores'
    const events = await this.prisma.event.findMany({
      where: { serverId: server.id, eventCategory: 'combat' },
      orderBy: { sourceTime: 'desc' },
      take: 200,
    });

    const scoresEvent = events.find((event) => {
      const payload = event.payload as RawCombatPayload;
      return payload?.event_kind === 'scores';
    });

    if (!scoresEvent) {
      return {
        server_login: serverLogin,
        scores_section: null,
        scores_snapshot: null,
        scores_result: null,
        source_time: null,
        event_id: null,
        no_scores_available: true,
      };
    }

    const payload = scoresEvent.payload as RawCombatPayload;

    return {
      server_login: serverLogin,
      scores_section: payload.scores_section ?? null,
      scores_snapshot: payload.scores_snapshot ?? null,
      scores_result: payload.scores_result ?? null,
      source_time: new Date(Number(scoresEvent.sourceTime)).toISOString(),
      event_id: scoresEvent.eventId,
    };
  }
}
