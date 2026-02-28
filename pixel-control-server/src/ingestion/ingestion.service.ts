import { Injectable, Logger } from '@nestjs/common';
import { Prisma } from '@prisma/client';

import { AckResponse, ErrorResponse } from '../common/dto/ack-response.dto';
import { EventEnvelopeDto } from '../common/dto/event-envelope.dto';
import { ConnectivityService } from '../connectivity/connectivity.service';
import { PrismaService } from '../prisma/prisma.service';
import { BatchAckResponse, BatchService } from './services/batch.service';
import { CombatService } from './services/combat.service';
import { LifecycleService } from './services/lifecycle.service';
import { ModeService } from './services/mode.service';
import { PlayerService } from './services/player.service';

@Injectable()
export class IngestionService {
  private readonly logger = new Logger(IngestionService.name);

  constructor(
    private readonly prisma: PrismaService,
    private readonly connectivityService: ConnectivityService,
    private readonly lifecycleService: LifecycleService,
    private readonly combatService: CombatService,
    private readonly playerService: PlayerService,
    private readonly modeService: ModeService,
    private readonly batchService: BatchService,
  ) {}

  /**
   * Main ingestion entry point. Called by IngestionController for every incoming event.
   * Steps:
   * 1. Idempotency check against unified Event table.
   * 2. Look up or auto-register server.
   * 3. Store event in unified Event table.
   * 4. Dispatch to category-specific service.
   * 5. Return ack response.
   */
  async ingestEvent(
    serverLogin: string,
    pluginVersion: string | undefined,
    envelope: EventEnvelopeDto,
  ): Promise<AckResponse | BatchAckResponse | ErrorResponse> {
    try {
      // 1. Idempotency check on unified Event table
      const existing = await this.prisma.event.findUnique({
        where: { idempotencyKey: envelope.idempotency_key },
      });

      if (existing) {
        return { ack: { status: 'accepted', disposition: 'duplicate' } };
      }

      // 2. Look up or auto-register server
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

      const serverId = server.id;

      // 3. Store in unified Event table (unless batch — batch envelope is a wrapper, not stored itself)
      if (envelope.event_category !== 'batch') {
        await this.prisma.event.create({
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
        });
      }

      // 4. Dispatch to category-specific service
      const dispatchResult = await this.dispatchToCategory(
        serverId,
        serverLogin,
        pluginVersion,
        envelope,
      );

      // For batch events, the dispatch result is the aggregate batch ack
      if (dispatchResult !== undefined) {
        return dispatchResult;
      }

      return { ack: { status: 'accepted' } };
    } catch (error) {
      this.logger.error('Unexpected error ingesting event', error);
      return {
        error: {
          code: 'internal_error',
          retryable: true,
          retry_after_seconds: 5,
        },
      };
    }
  }

  /**
   * Dispatches a validated, stored event to the appropriate category-specific service.
   * Returns BatchAckResponse for batch events, void for all others.
   */
  private async dispatchToCategory(
    serverId: string,
    serverLogin: string,
    pluginVersion: string | undefined,
    envelope: EventEnvelopeDto,
  ): Promise<BatchAckResponse | void> {
    const category = envelope.event_category;

    switch (category) {
      case 'connectivity':
        await this.connectivityService.ingestConnectivityEvent(
          serverId,
          pluginVersion,
          envelope,
        );
        return;

      case 'lifecycle':
        await this.lifecycleService.ingestLifecycleEvent(serverId, envelope);
        return;

      case 'combat':
        await this.combatService.ingestCombatEvent(serverId, envelope);
        return;

      case 'player':
        await this.playerService.ingestPlayerEvent(serverId, envelope);
        return;

      case 'mode':
        await this.modeService.ingestModeEvent(serverId, envelope);
        return;

      case 'batch':
        return this.batchService.ingestBatchEvent(
          envelope,
          (innerEnvelope) =>
            this.ingestEvent(serverLogin, pluginVersion, innerEnvelope) as Promise<
              AckResponse | { error: unknown }
            >,
        );

      default:
        this.logger.warn(
          `Unknown event category '${category}' — accepted and stored, no category-specific processing.`,
        );
        return;
    }
  }
}
