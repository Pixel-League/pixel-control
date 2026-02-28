import { Injectable, Logger } from '@nestjs/common';

import { ServerResolverService } from '../common/services/server-resolver.service';
import { PrismaService } from '../prisma/prisma.service';

export interface LifecyclePhaseState {
  state: string;
  variant: string;
  source_time: string;
  event_id: string;
}

export interface WarmupPauseState {
  active: boolean;
  last_variant: string | null;
  source_time: string | null;
}

export interface LifecycleStateResponse {
  server_login: string;
  current_phase: string | null;
  match: LifecyclePhaseState | null;
  map: LifecyclePhaseState | null;
  round: LifecyclePhaseState | null;
  warmup: WarmupPauseState;
  pause: WarmupPauseState;
  last_updated: string | null;
}

export interface MapRotationResponse {
  server_login: string;
  map_pool: unknown[];
  map_pool_size: number;
  current_map: Record<string, unknown> | null;
  current_map_index: number | null;
  next_maps: unknown[];
  played_map_order: unknown[];
  played_map_count: number;
  series_targets: Record<string, unknown> | null;
  veto: Record<string, unknown> | null;
  source_time: string | null;
  event_id: string | null;
  no_rotation_data?: boolean;
}

export interface AggregateStatsResponse {
  server_login: string;
  aggregates: AggregateEntry[];
}

export interface AggregateEntry {
  scope: string;
  counter_scope: string | null;
  player_counters_delta: Record<string, unknown> | null;
  totals: Record<string, unknown> | null;
  team_counters_delta: unknown[] | null;
  team_summary: Record<string, unknown> | null;
  tracked_player_count: number | null;
  window: Record<string, unknown> | null;
  source_coverage: Record<string, unknown> | null;
  win_context: Record<string, unknown> | null;
  source_time: string;
  event_id: string;
}

interface RawLifecyclePayload {
  variant?: string;
  map_rotation?: {
    map_pool?: unknown[];
    map_pool_size?: number;
    current_map?: Record<string, unknown>;
    current_map_index?: number;
    next_maps?: unknown[];
    played_map_order?: unknown[];
    played_map_count?: number;
    series_targets?: Record<string, unknown>;
    veto_draft_mode?: string;
    veto_draft_session_status?: string;
    matchmaking_ready_armed?: boolean;
    veto_draft_actions?: Record<string, unknown>;
    veto_result?: Record<string, unknown>;
    matchmaking_lifecycle?: Record<string, unknown>;
  };
  aggregate_stats?: {
    scope?: string;
    counter_scope?: string;
    player_counters_delta?: Record<string, unknown>;
    totals?: Record<string, unknown>;
    team_counters_delta?: unknown[];
    team_summary?: Record<string, unknown>;
    tracked_player_count?: number;
    window?: Record<string, unknown>;
    source_coverage?: Record<string, unknown>;
    win_context?: Record<string, unknown>;
  };
}

type EventRow = {
  id: string;
  eventId: string;
  payload: unknown;
  sourceTime: bigint;
};

@Injectable()
export class LifecycleReadService {
  private readonly logger = new Logger(LifecycleReadService.name);

  constructor(
    private readonly serverResolver: ServerResolverService,
    private readonly prisma: PrismaService,
  ) {}

  async getLifecycleState(serverLogin: string): Promise<LifecycleStateResponse> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    const events = await this.prisma.event.findMany({
      where: { serverId: server.id, eventCategory: 'lifecycle' },
      orderBy: { sourceTime: 'desc' },
      take: 200,
    });

    if (events.length === 0) {
      return {
        server_login: serverLogin,
        current_phase: null,
        match: null,
        map: null,
        round: null,
        warmup: { active: false, last_variant: null, source_time: null },
        pause: { active: false, last_variant: null, source_time: null },
        last_updated: null,
      };
    }

    // Build latest state for each phase prefix
    let matchState: LifecyclePhaseState | null = null;
    let mapState: LifecyclePhaseState | null = null;
    let roundState: LifecyclePhaseState | null = null;
    let warmupState: WarmupPauseState = { active: false, last_variant: null, source_time: null };
    let pauseState: WarmupPauseState = { active: false, last_variant: null, source_time: null };

    let latestTime = 0n;

    for (const event of events) {
      const payload = event.payload as RawLifecyclePayload;
      const variant = payload?.variant ?? '';
      const sourceTimeMs = event.sourceTime;
      const sourceTimeIso = new Date(Number(sourceTimeMs)).toISOString();

      if (sourceTimeMs > latestTime) {
        latestTime = sourceTimeMs;
      }

      const parts = variant.split('.');
      const prefix = parts[0] ?? '';
      const phase = parts[1] ?? '';

      if (prefix === 'match' && !matchState) {
        matchState = { state: phase, variant, source_time: sourceTimeIso, event_id: event.eventId };
      } else if (prefix === 'map' && !mapState) {
        mapState = { state: phase, variant, source_time: sourceTimeIso, event_id: event.eventId };
      } else if (prefix === 'round' && !roundState) {
        roundState = { state: phase, variant, source_time: sourceTimeIso, event_id: event.eventId };
      } else if (prefix === 'warmup' && warmupState.last_variant === null) {
        warmupState = {
          active: phase === 'start',
          last_variant: variant,
          source_time: sourceTimeIso,
        };
      } else if (prefix === 'pause' && pauseState.last_variant === null) {
        pauseState = {
          active: phase === 'start',
          last_variant: variant,
          source_time: sourceTimeIso,
        };
      }
    }

    // Determine current_phase from the most recent event
    const latestEvent = events[0];
    const latestPayload = latestEvent?.payload as RawLifecyclePayload;
    const latestVariant = latestPayload?.variant ?? '';
    const currentPhase = latestVariant.split('.')[0] ?? null;

    return {
      server_login: serverLogin,
      current_phase: currentPhase || null,
      match: matchState,
      map: mapState,
      round: roundState,
      warmup: warmupState,
      pause: pauseState,
      last_updated: latestTime > 0n ? new Date(Number(latestTime)).toISOString() : null,
    };
  }

  async getMapRotation(serverLogin: string): Promise<MapRotationResponse> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    const events = await this.prisma.event.findMany({
      where: { serverId: server.id, eventCategory: 'lifecycle' },
      orderBy: { sourceTime: 'desc' },
      take: 200,
    });

    // Find the latest event with map_rotation data
    const rotationEvent = events.find((e) => {
      const payload = e.payload as RawLifecyclePayload;
      return payload?.map_rotation != null;
    });

    if (!rotationEvent) {
      return {
        server_login: serverLogin,
        map_pool: [],
        map_pool_size: 0,
        current_map: null,
        current_map_index: null,
        next_maps: [],
        played_map_order: [],
        played_map_count: 0,
        series_targets: null,
        veto: null,
        source_time: null,
        event_id: null,
        no_rotation_data: true,
      };
    }

    const payload = rotationEvent.payload as RawLifecyclePayload;
    const mr = payload.map_rotation!;

    return {
      server_login: serverLogin,
      map_pool: mr.map_pool ?? [],
      map_pool_size: mr.map_pool_size ?? (mr.map_pool?.length ?? 0),
      current_map: mr.current_map ?? null,
      current_map_index: mr.current_map_index ?? null,
      next_maps: mr.next_maps ?? [],
      played_map_order: mr.played_map_order ?? [],
      played_map_count: mr.played_map_count ?? 0,
      series_targets: mr.series_targets ?? null,
      veto: {
        mode: mr.veto_draft_mode ?? null,
        session_status: mr.veto_draft_session_status ?? null,
        ready_armed: mr.matchmaking_ready_armed ?? false,
        actions: mr.veto_draft_actions ?? null,
        result: mr.veto_result ?? null,
        lifecycle: mr.matchmaking_lifecycle ?? null,
      },
      source_time: new Date(Number(rotationEvent.sourceTime)).toISOString(),
      event_id: rotationEvent.eventId,
    };
  }

  async getAggregateStats(
    serverLogin: string,
    scope?: string,
  ): Promise<AggregateStatsResponse> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    const events = await this.prisma.event.findMany({
      where: { serverId: server.id, eventCategory: 'lifecycle' },
      orderBy: { sourceTime: 'desc' },
      take: 200,
    });

    // Find events with aggregate_stats, then filter by scope if provided
    const aggregateEvents = events.filter((e) => {
      const payload = e.payload as RawLifecyclePayload;
      if (!payload?.aggregate_stats) return false;

      if (scope) {
        return payload.aggregate_stats.scope === scope;
      }
      return true;
    });

    // Return only the latest per scope (round + map), up to 2 entries by default
    const seen = new Set<string>();
    const result: AggregateEntry[] = [];

    for (const event of aggregateEvents) {
      const payload = event.payload as RawLifecyclePayload;
      const agg = payload.aggregate_stats!;
      const aggScope = agg.scope ?? 'unknown';

      if (scope) {
        // When a specific scope is requested, return only the latest one
        if (seen.has(aggScope)) continue;
      } else {
        // Return the latest of each scope type
        if (seen.has(aggScope)) continue;
      }

      seen.add(aggScope);
      result.push({
        scope: aggScope,
        counter_scope: agg.counter_scope ?? null,
        player_counters_delta: agg.player_counters_delta ?? null,
        totals: agg.totals ?? null,
        team_counters_delta: agg.team_counters_delta ?? null,
        team_summary: agg.team_summary ?? null,
        tracked_player_count: agg.tracked_player_count ?? null,
        window: agg.window ?? null,
        source_coverage: agg.source_coverage ?? null,
        win_context: agg.win_context ?? null,
        source_time: new Date(Number(event.sourceTime)).toISOString(),
        event_id: event.eventId,
      });
    }

    return { server_login: serverLogin, aggregates: result };
  }
}
