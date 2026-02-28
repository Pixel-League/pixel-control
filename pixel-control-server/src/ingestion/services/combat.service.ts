import { Injectable, Logger } from '@nestjs/common';

import { EventEnvelopeDto } from '../../common/dto/event-envelope.dto';

/**
 * Handles combat event category ingestion.
 * P1: placeholder — event is already stored in the unified Event table by IngestionService.
 * P2 will add combat statistics derivation here.
 */
@Injectable()
export class CombatService {
  private readonly logger = new Logger(CombatService.name);

  async ingestCombatEvent(
    serverId: string,
    envelope: EventEnvelopeDto,
  ): Promise<void> {
    this.logger.debug(
      `Combat event stored: serverId=${serverId}, event=${envelope.event_name}`,
    );
  }
}
