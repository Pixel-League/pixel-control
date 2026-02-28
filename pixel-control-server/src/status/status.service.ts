import { Injectable, Logger, NotFoundException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';

import { isServerOnline } from '../common/utils/online-status.util';
import { PrismaService } from '../prisma/prisma.service';

export interface PlayerCounts {
  active: number;
  total: number;
  spectators: number;
}

export interface EventCounts {
  total: number;
  by_category: Record<string, number>;
}

export interface ServerStatusResponse {
  server_login: string;
  server_name: string | null;
  linked: boolean;
  online: boolean;
  game_mode: string | null;
  title_id: string | null;
  plugin_version: string | null;
  last_heartbeat: string | null;
  player_counts: PlayerCounts;
  event_counts: EventCounts;
}

export interface QueueHealth {
  depth: number;
  max_size: number;
  high_watermark: number;
  dropped_on_capacity: number;
  dropped_on_identity_validation: number;
  recovery_flush_pending: boolean;
}

export interface RetryHealth {
  max_retry_attempts: number;
  retry_backoff_ms: number;
  dispatch_batch_size: number;
}

export interface OutageHealth {
  active: boolean;
  started_at: number | null;
  failure_count: number;
  last_error_code: string | null;
  recovery_flush_pending: boolean;
}

export interface PluginHealth {
  queue: QueueHealth;
  retry: RetryHealth;
  outage: OutageHealth;
}

export interface ConnectivityMetrics {
  total_connectivity_events: number;
  last_registration_at: string | null;
  last_heartbeat_at: string | null;
  heartbeat_count: number;
  registration_count: number;
}

export interface ServerHealthResponse {
  server_login: string;
  online: boolean;
  plugin_health: PluginHealth;
  connectivity_metrics: ConnectivityMetrics;
}

interface HeartbeatPayload {
  queue?: QueueHealth;
  retry?: RetryHealth;
  outage?: OutageHealth;
}

interface HeartbeatMetadata {
  queue?: QueueHealth;
  retry?: RetryHealth;
  outage?: OutageHealth;
}

interface PlayerSnapshot {
  active?: number;
  total?: number;
  spectators?: number;
}

interface ContextPayload {
  players?: PlayerSnapshot;
}

interface HeartbeatPayloadWithContext {
  context?: ContextPayload;
}

@Injectable()
export class StatusService {
  private readonly logger = new Logger(StatusService.name);
  private readonly onlineThresholdSeconds: number;

  constructor(
    private readonly prisma: PrismaService,
    private readonly config: ConfigService,
  ) {
    this.onlineThresholdSeconds =
      this.config.get<number>('ONLINE_THRESHOLD_SECONDS') ?? 360;
  }

  async getServerStatus(serverLogin: string): Promise<ServerStatusResponse> {
    const server = await this.prisma.server.findUnique({
      where: { serverLogin },
    });

    if (!server) {
      throw new NotFoundException(
        `Server '${serverLogin}' not found`,
      );
    }

    const online = server.lastHeartbeat
      ? isServerOnline(server.lastHeartbeat, this.onlineThresholdSeconds)
      : false;

    // Get player counts from the latest connectivity heartbeat payload
    const playerCounts = await this.extractLatestPlayerCounts(server.id);

    // Get event counts from unified Event table by category
    const eventCounts = await this.computeEventCounts(server.id);

    return {
      server_login: server.serverLogin,
      server_name: server.serverName ?? null,
      linked: server.linked,
      online,
      game_mode: server.gameMode ?? null,
      title_id: server.titleId ?? null,
      plugin_version: server.pluginVersion ?? null,
      last_heartbeat: server.lastHeartbeat
        ? server.lastHeartbeat.toISOString()
        : null,
      player_counts: playerCounts,
      event_counts: eventCounts,
    };
  }

  async getServerHealth(serverLogin: string): Promise<ServerHealthResponse> {
    const server = await this.prisma.server.findUnique({
      where: { serverLogin },
    });

    if (!server) {
      throw new NotFoundException(
        `Server '${serverLogin}' not found`,
      );
    }

    const online = server.lastHeartbeat
      ? isServerOnline(server.lastHeartbeat, this.onlineThresholdSeconds)
      : false;

    // Get plugin health from the latest heartbeat connectivity event
    const pluginHealth = await this.extractPluginHealth(server.id);

    // Get connectivity metrics
    const connectivityMetrics = await this.computeConnectivityMetrics(
      server.id,
    );

    return {
      server_login: server.serverLogin,
      online,
      plugin_health: pluginHealth,
      connectivity_metrics: connectivityMetrics,
    };
  }

  private async extractLatestPlayerCounts(serverId: string): Promise<PlayerCounts> {
    // Find the latest connectivity heartbeat event
    const latestHeartbeat = await this.prisma.connectivityEvent.findFirst({
      where: {
        serverId,
        eventName: { contains: 'heartbeat' },
      },
      orderBy: { receivedAt: 'desc' },
    });

    if (!latestHeartbeat) {
      // Fall back to latest registration event
      const latestReg = await this.prisma.connectivityEvent.findFirst({
        where: { serverId },
        orderBy: { receivedAt: 'desc' },
      });

      if (!latestReg) {
        return { active: 0, total: 0, spectators: 0 };
      }

      return this.extractPlayerCountsFromPayload(
        latestReg.payload as HeartbeatPayloadWithContext,
      );
    }

    return this.extractPlayerCountsFromPayload(
      latestHeartbeat.payload as HeartbeatPayloadWithContext,
    );
  }

  private extractPlayerCountsFromPayload(
    payload: HeartbeatPayloadWithContext,
  ): PlayerCounts {
    const players = payload?.context?.players;
    if (!players) {
      return { active: 0, total: 0, spectators: 0 };
    }

    return {
      active: players.active ?? 0,
      total: players.total ?? 0,
      spectators: players.spectators ?? 0,
    };
  }

  private async computeEventCounts(serverId: string): Promise<EventCounts> {
    const rows = await this.prisma.event.groupBy({
      by: ['eventCategory'],
      where: { serverId },
      _count: { id: true },
    });

    const byCategory: Record<string, number> = {};
    let total = 0;

    for (const row of rows) {
      byCategory[row.eventCategory] = row._count.id;
      total += row._count.id;
    }

    return { total, by_category: byCategory };
  }

  private async extractPluginHealth(serverId: string): Promise<PluginHealth> {
    const defaultHealth: PluginHealth = {
      queue: {
        depth: 0,
        max_size: 2000,
        high_watermark: 0,
        dropped_on_capacity: 0,
        dropped_on_identity_validation: 0,
        recovery_flush_pending: false,
      },
      retry: {
        max_retry_attempts: 3,
        retry_backoff_ms: 250,
        dispatch_batch_size: 3,
      },
      outage: {
        active: false,
        started_at: null,
        failure_count: 0,
        last_error_code: null,
        recovery_flush_pending: false,
      },
    };

    // Try to get health from the latest heartbeat payload
    const latestHeartbeat = await this.prisma.connectivityEvent.findFirst({
      where: {
        serverId,
        eventName: { contains: 'heartbeat' },
      },
      orderBy: { receivedAt: 'desc' },
    });

    if (!latestHeartbeat) {
      // Try metadata from the latest heartbeat in Event table
      const latestEvent = await this.prisma.event.findFirst({
        where: {
          serverId,
          eventName: { contains: 'heartbeat' },
        },
        orderBy: { receivedAt: 'desc' },
      });

      if (!latestEvent) {
        return defaultHealth;
      }

      return this.extractHealthFromPayloadOrMetadata(
        latestEvent.payload as HeartbeatPayload,
        latestEvent.metadata as HeartbeatMetadata | null,
        defaultHealth,
      );
    }

    return this.extractHealthFromPayloadOrMetadata(
      latestHeartbeat.payload as HeartbeatPayload,
      latestHeartbeat.metadata as HeartbeatMetadata | null,
      defaultHealth,
    );
  }

  private extractHealthFromPayloadOrMetadata(
    payload: HeartbeatPayload,
    metadata: HeartbeatMetadata | null,
    defaults: PluginHealth,
  ): PluginHealth {
    // Health data can be in the payload directly or in metadata
    const source = payload ?? {};
    const metaSource = metadata ?? {};

    const queue = source.queue ?? metaSource.queue ?? defaults.queue;
    const retry = source.retry ?? metaSource.retry ?? defaults.retry;
    const outage = source.outage ?? metaSource.outage ?? defaults.outage;

    return {
      queue: {
        depth: (queue as QueueHealth).depth ?? 0,
        max_size: (queue as QueueHealth).max_size ?? defaults.queue.max_size,
        high_watermark: (queue as QueueHealth).high_watermark ?? 0,
        dropped_on_capacity: (queue as QueueHealth).dropped_on_capacity ?? 0,
        dropped_on_identity_validation:
          (queue as QueueHealth).dropped_on_identity_validation ?? 0,
        recovery_flush_pending:
          (queue as QueueHealth).recovery_flush_pending ?? false,
      },
      retry: {
        max_retry_attempts:
          (retry as RetryHealth).max_retry_attempts ??
          defaults.retry.max_retry_attempts,
        retry_backoff_ms:
          (retry as RetryHealth).retry_backoff_ms ??
          defaults.retry.retry_backoff_ms,
        dispatch_batch_size:
          (retry as RetryHealth).dispatch_batch_size ??
          defaults.retry.dispatch_batch_size,
      },
      outage: {
        active: (outage as OutageHealth).active ?? false,
        started_at: (outage as OutageHealth).started_at ?? null,
        failure_count: (outage as OutageHealth).failure_count ?? 0,
        last_error_code: (outage as OutageHealth).last_error_code ?? null,
        recovery_flush_pending:
          (outage as OutageHealth).recovery_flush_pending ?? false,
      },
    };
  }

  private async computeConnectivityMetrics(
    serverId: string,
  ): Promise<ConnectivityMetrics> {
    const total = await this.prisma.connectivityEvent.count({
      where: { serverId },
    });

    const heartbeatCount = await this.prisma.connectivityEvent.count({
      where: { serverId, eventName: { contains: 'heartbeat' } },
    });

    const registrationCount = await this.prisma.connectivityEvent.count({
      where: { serverId, eventName: { contains: 'registration' } },
    });

    const lastRegistration = await this.prisma.connectivityEvent.findFirst({
      where: { serverId, eventName: { contains: 'registration' } },
      orderBy: { receivedAt: 'desc' },
    });

    const lastHeartbeat = await this.prisma.connectivityEvent.findFirst({
      where: { serverId, eventName: { contains: 'heartbeat' } },
      orderBy: { receivedAt: 'desc' },
    });

    return {
      total_connectivity_events: total,
      last_registration_at: lastRegistration
        ? lastRegistration.receivedAt.toISOString()
        : null,
      last_heartbeat_at: lastHeartbeat
        ? lastHeartbeat.receivedAt.toISOString()
        : null,
      heartbeat_count: heartbeatCount,
      registration_count: registrationCount,
    };
  }
}
