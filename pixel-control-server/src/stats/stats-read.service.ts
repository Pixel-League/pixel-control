import { Injectable, Logger, NotFoundException } from '@nestjs/common';

import { PaginatedResponse, paginate } from '../common/dto/read-response.dto';
import { ServerResolverService } from '../common/services/server-resolver.service';
import { PrismaService } from '../prisma/prisma.service';

// ---------------------------------------------------------------------------
// Per-map / per-series combat stats interfaces
// ---------------------------------------------------------------------------

export interface PlayerCountersDelta {
  kills: number;
  deaths: number;
  hits: number;
  shots: number;
  misses: number;
  rockets: number;
  lasers: number;
  accuracy: number;
}

export interface MapCombatStatsEntry {
  map_uid: string;
  map_name: string;
  played_at: string;
  duration_seconds: number;
  player_stats: Record<string, PlayerCountersDelta>;
  team_stats: unknown[];
  totals: Record<string, number>;
  win_context: Record<string, unknown>;
  event_id: string;
}

export interface MapCombatStatsListResponse {
  server_login: string;
  maps: MapCombatStatsEntry[];
  pagination: {
    total: number;
    limit: number;
    offset: number;
  };
}

export interface MapPlayerCombatStatsResponse {
  server_login: string;
  map_uid: string;
  map_name: string;
  player_login: string;
  counters: PlayerCountersDelta;
  played_at: string;
}

export interface SeriesCombatEntry {
  match_started_at: string;
  match_ended_at: string | null;
  total_maps_played: number;
  maps: MapCombatStatsEntry[];
  series_totals: Record<string, number>;
  series_win_context: Record<string, unknown> | null;
}

export interface SeriesCombatListResponse {
  server_login: string;
  series: SeriesCombatEntry[];
  pagination: {
    total: number;
    limit: number;
    offset: number;
  };
}

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

interface RawLifecyclePayload {
  variant?: string;
  map_rotation?: {
    current_map?: {
      uid?: string;
      name?: string;
      file?: string;
      environment?: string;
    };
  };
  aggregate_stats?: {
    scope?: string;
    player_counters_delta?: Record<string, {
      kills?: number;
      deaths?: number;
      hits?: number;
      shots?: number;
      misses?: number;
      rockets?: number;
      lasers?: number;
      accuracy?: number;
    }>;
    team_counters_delta?: unknown[];
    totals?: Record<string, number>;
    win_context?: Record<string, unknown>;
    window?: {
      started_at?: number;
      ended_at?: number;
      duration_seconds?: number;
    };
    window_state?: string;
    counter_keys?: string[];
    counter_scope?: string;
    tracked_player_count?: number;
  };
}

type LifecycleEventRow = {
  id: string;
  eventId: string;
  payload: unknown;
  sourceTime: bigint;
};

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

  // ---------------------------------------------------------------------------
  // Per-map / per-series combat stats
  // ---------------------------------------------------------------------------

  /**
   * Private helper: extracts a MapCombatStatsEntry from a lifecycle map.end event row.
   * Returns null if the event does not contain the required fields.
   */
  private extractMapCombatEntry(event: LifecycleEventRow): MapCombatStatsEntry | null {
    const payload = event.payload as RawLifecyclePayload;

    if (payload?.variant !== 'map.end') return null;
    if (payload?.aggregate_stats?.scope !== 'map') return null;

    const agg = payload.aggregate_stats;
    const currentMap = payload?.map_rotation?.current_map;

    if (!currentMap?.uid) return null;

    const rawDelta = agg.player_counters_delta ?? {};
    const playerStats: Record<string, PlayerCountersDelta> = {};
    for (const [login, c] of Object.entries(rawDelta)) {
      playerStats[login] = {
        kills: c.kills ?? 0,
        deaths: c.deaths ?? 0,
        hits: c.hits ?? 0,
        shots: c.shots ?? 0,
        misses: c.misses ?? 0,
        rockets: c.rockets ?? 0,
        lasers: c.lasers ?? 0,
        accuracy: c.accuracy ?? (c.shots ? (c.hits ?? 0) / c.shots : 0),
      };
    }

    const window = agg.window;
    let durationSeconds = 0;
    if (window?.duration_seconds !== undefined) {
      durationSeconds = window.duration_seconds;
    } else if (window?.started_at !== undefined && window?.ended_at !== undefined) {
      durationSeconds = Math.round((window.ended_at - window.started_at) / 1000);
    }

    return {
      map_uid: currentMap.uid,
      map_name: currentMap.name ?? currentMap.uid,
      played_at: new Date(Number(event.sourceTime)).toISOString(),
      duration_seconds: durationSeconds,
      player_stats: playerStats,
      team_stats: (agg.team_counters_delta as unknown[]) ?? [],
      totals: (agg.totals as Record<string, number>) ?? {},
      win_context: (agg.win_context as Record<string, unknown>) ?? {},
      event_id: event.eventId,
    };
  }

  /**
   * Fetches lifecycle events for the given server and returns only the raw rows.
   */
  private async fetchLifecycleEvents(
    serverId: string,
    take: number,
    sinceMs?: bigint,
    untilMs?: bigint,
  ): Promise<LifecycleEventRow[]> {
    return this.prisma.event.findMany({
      where: {
        serverId,
        eventCategory: 'lifecycle',
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
      take,
    }) as Promise<LifecycleEventRow[]>;
  }

  async getMapCombatStatsList(
    serverLogin: string,
    limit: number,
    offset: number,
    since?: string,
    until?: string,
  ): Promise<MapCombatStatsListResponse> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    const sinceMs = since ? BigInt(new Date(since).getTime()) : undefined;
    const untilMs = until ? BigInt(new Date(until).getTime()) : undefined;

    const events = await this.fetchLifecycleEvents(server.id, 1000, sinceMs, untilMs);

    const mapEntries: MapCombatStatsEntry[] = [];
    for (const event of events) {
      const entry = this.extractMapCombatEntry(event);
      if (entry) mapEntries.push(entry);
    }

    // events are already ordered desc; mapEntries preserves that order (most recent first)
    const total = mapEntries.length;
    const data = mapEntries.slice(offset, offset + limit);

    return {
      server_login: serverLogin,
      maps: data,
      pagination: { total, limit, offset },
    };
  }

  async getMapCombatStats(
    serverLogin: string,
    mapUid: string,
  ): Promise<MapCombatStatsEntry> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    const events = await this.fetchLifecycleEvents(server.id, 1000);

    for (const event of events) {
      const entry = this.extractMapCombatEntry(event);
      if (entry && entry.map_uid === mapUid) {
        return entry;
      }
    }

    throw new NotFoundException(
      `No combat stats found for map '${mapUid}' on server '${serverLogin}'`,
    );
  }

  async getMapPlayerCombatStats(
    serverLogin: string,
    mapUid: string,
    playerLogin: string,
  ): Promise<MapPlayerCombatStatsResponse> {
    const mapEntry = await this.getMapCombatStats(serverLogin, mapUid);

    const counters = mapEntry.player_stats[playerLogin];
    if (!counters) {
      throw new NotFoundException(
        `No combat stats found for player '${playerLogin}' on map '${mapUid}' (server '${serverLogin}')`,
      );
    }

    return {
      server_login: serverLogin,
      map_uid: mapEntry.map_uid,
      map_name: mapEntry.map_name,
      player_login: playerLogin,
      counters,
      played_at: mapEntry.played_at,
    };
  }

  async getSeriesCombatStatsList(
    serverLogin: string,
    limit: number,
    offset: number,
    since?: string,
    until?: string,
  ): Promise<SeriesCombatListResponse> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    // Fetch a generous window of lifecycle events (series span multiple maps)
    const events = (await this.prisma.event.findMany({
      where: { serverId: server.id, eventCategory: 'lifecycle' },
      orderBy: { sourceTime: 'asc' },
      take: 2000,
    })) as LifecycleEventRow[];

    // Pair match.begin / match.end events to build series boundaries
    type SeriesBoundary = {
      beginTime: bigint;
      endTime: bigint | null;
      endPayload: RawLifecyclePayload | null;
    };

    const series: SeriesBoundary[] = [];
    let openBeginTime: bigint | null = null;

    for (const event of events) {
      const payload = event.payload as RawLifecyclePayload;
      const variant = payload?.variant ?? '';

      if (variant === 'match.begin') {
        openBeginTime = event.sourceTime;
      } else if (variant === 'match.end' && openBeginTime !== null) {
        series.push({
          beginTime: openBeginTime,
          endTime: event.sourceTime,
          endPayload: payload,
        });
        openBeginTime = null;
      }
    }

    // Build SeriesCombatEntry for each complete series (desc order)
    const seriesEntries: SeriesCombatEntry[] = [];

    for (const boundary of series) {
      const mapsInSeries: MapCombatStatsEntry[] = [];

      for (const event of events) {
        if (event.sourceTime < boundary.beginTime) continue;
        if (boundary.endTime !== null && event.sourceTime > boundary.endTime) continue;

        const entry = this.extractMapCombatEntry(event);
        if (entry) mapsInSeries.push(entry);
      }

      // Compute series_totals by summing all map totals
      const seriesTotals: Record<string, number> = {};
      for (const mapEntry of mapsInSeries) {
        for (const [key, val] of Object.entries(mapEntry.totals)) {
          seriesTotals[key] = (seriesTotals[key] ?? 0) + val;
        }
      }

      // Extract series_win_context from match.end aggregate_stats if present
      const endPayload = boundary.endPayload;
      const seriesWinContext: Record<string, unknown> | null =
        endPayload?.aggregate_stats?.win_context ??
        (endPayload as Record<string, unknown> | null)?.['win_context'] as Record<string, unknown> | null ??
        null;

      seriesEntries.push({
        match_started_at: new Date(Number(boundary.beginTime)).toISOString(),
        match_ended_at:
          boundary.endTime !== null
            ? new Date(Number(boundary.endTime)).toISOString()
            : null,
        total_maps_played: mapsInSeries.length,
        maps: mapsInSeries,
        series_totals: seriesTotals,
        series_win_context: seriesWinContext,
      });
    }

    // Sort most recent first
    seriesEntries.sort(
      (a, b) =>
        new Date(b.match_started_at).getTime() - new Date(a.match_started_at).getTime(),
    );

    // Apply time-range filter if provided
    const sinceMs = since ? new Date(since).getTime() : null;
    const untilMs = until ? new Date(until).getTime() : null;

    const filtered = seriesEntries.filter((s) => {
      const startedAt = new Date(s.match_started_at).getTime();
      if (sinceMs !== null && startedAt < sinceMs) return false;
      if (untilMs !== null && startedAt > untilMs) return false;
      return true;
    });

    const total = filtered.length;
    const data = filtered.slice(offset, offset + limit);

    return {
      server_login: serverLogin,
      series: data,
      pagination: { total, limit, offset },
    };
  }
}
