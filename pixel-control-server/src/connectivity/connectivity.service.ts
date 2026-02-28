import { Injectable, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { Prisma } from '@prisma/client';

import { AckResponse, ErrorResponse } from '../common/dto/ack-response.dto';
import { EventEnvelopeDto } from '../common/dto/event-envelope.dto';
import { isServerOnline } from '../common/utils/online-status.util';
import { PrismaService } from '../prisma/prisma.service';

interface PluginRegistrationContext {
  server?: {
    login?: string;
    title_id?: string;
    game_mode?: string;
    name?: string;
  };
}

interface PluginRegistrationPayload {
  type?: string;
  context?: PluginRegistrationContext;
  plugin?: {
    version?: string;
  };
}

@Injectable()
export class ConnectivityService {
  private readonly logger = new Logger(ConnectivityService.name);
  private readonly onlineThresholdSeconds: number;

  constructor(
    private readonly prisma: PrismaService,
    private readonly config: ConfigService,
  ) {
    this.onlineThresholdSeconds =
      this.config.get<number>('ONLINE_THRESHOLD_SECONDS') ?? 360;
  }

  /**
   * Ingests a connectivity event (plugin_registration or plugin_heartbeat).
   * Writes to ConnectivityEvent table (backward compat) and updates Server heartbeat/metadata.
   * Idempotency and server lookup/auto-registration are handled by IngestionService BEFORE calling this.
   *
   * @param serverId - The resolved server UUID (already guaranteed to exist in DB)
   * @param pluginVersion - Plugin version from header (optional)
   * @param envelope - Validated event envelope
   */
  async ingestConnectivityEvent(
    serverId: string,
    pluginVersion: string | undefined,
    envelope: EventEnvelopeDto,
  ): Promise<void> {
    const now = new Date();
    const serverUpdate: {
      lastHeartbeat: Date;
      online: boolean;
      pluginVersion?: string;
      serverName?: string;
      gameMode?: string;
      titleId?: string;
    } = {
      lastHeartbeat: now,
      online: isServerOnline(now, this.onlineThresholdSeconds),
    };

    if (pluginVersion) {
      serverUpdate.pluginVersion = pluginVersion;
    }

    const payload = envelope.payload as PluginRegistrationPayload;
    if (payload.type === 'plugin_registration' && payload.context?.server) {
      const ctx = payload.context.server;
      if (ctx.name) {
        serverUpdate.serverName = ctx.name;
      }
      if (ctx.game_mode) {
        serverUpdate.gameMode = ctx.game_mode;
      }
      if (ctx.title_id) {
        serverUpdate.titleId = ctx.title_id;
      }
    }

    // Store connectivity event and update server in a transaction
    await this.prisma.$transaction([
      this.prisma.connectivityEvent.create({
        data: {
          serverId,
          eventName: envelope.event_name,
          eventId: envelope.event_id,
          eventCategory: envelope.event_category,
          idempotencyKey: envelope.idempotency_key,
          sourceCallback: envelope.source_callback,
          sourceSequence: BigInt(envelope.source_sequence),
          sourceTime: BigInt(envelope.source_time),
          schemaVersion: envelope.schema_version,
          payload: envelope.payload as Prisma.InputJsonValue,
          metadata: envelope.metadata
            ? (envelope.metadata as Prisma.InputJsonValue)
            : Prisma.JsonNull,
        },
      }),
      this.prisma.server.update({
        where: { id: serverId },
        data: serverUpdate,
      }),
    ]);

    this.logger.debug(
      `Connectivity event stored: serverId=${serverId}, event=${envelope.event_name}`,
    );
  }

  /**
   * Legacy method used by ConnectivityController (kept for backward compatibility
   * with existing P0 controller tests until controller is replaced by IngestionController).
   * @deprecated Use ingestConnectivityEvent(serverId, ...) via IngestionService instead.
   */
  async ingestEvent(
    serverLogin: string,
    pluginVersion: string | undefined,
    envelope: EventEnvelopeDto,
  ): Promise<AckResponse | ErrorResponse> {
    try {
      // Idempotency check
      const existing = await this.prisma.connectivityEvent.findUnique({
        where: { idempotencyKey: envelope.idempotency_key },
      });

      if (existing) {
        return { ack: { status: 'accepted', disposition: 'duplicate' } };
      }

      // Look up or auto-register the server
      let server = await this.prisma.server.findUnique({
        where: { serverLogin },
      });

      if (!server) {
        this.logger.log(
          `Auto-registering unknown server '${serverLogin}' on first event`,
        );
        server = await this.prisma.server.create({
          data: { serverLogin, linked: false },
        });
      }

      await this.ingestConnectivityEvent(server.id, pluginVersion, envelope);

      return { ack: { status: 'accepted' } };
    } catch (error) {
      this.logger.error('Unexpected error ingesting connectivity event', error);
      return {
        error: {
          code: 'internal_error',
          retryable: true,
          retry_after_seconds: 5,
        },
      };
    }
  }
}
