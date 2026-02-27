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

      // Build server update from event
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

      // Store event and update server in a transaction
      await this.prisma.$transaction([
        this.prisma.connectivityEvent.create({
          data: {
            serverId: server.id,
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
          where: { id: server.id },
          data: serverUpdate,
        }),
      ]);

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
