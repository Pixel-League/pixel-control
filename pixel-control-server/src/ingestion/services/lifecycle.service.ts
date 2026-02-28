import { Injectable, Logger } from '@nestjs/common';

import { EventEnvelopeDto } from '../../common/dto/event-envelope.dto';

/**
 * Handles lifecycle event category ingestion.
 * P1: placeholder — event is already stored in the unified Event table by IngestionService.
 * P2 will add lifecycle state machine derivation here.
 */
@Injectable()
export class LifecycleService {
  private readonly logger = new Logger(LifecycleService.name);

  async ingestLifecycleEvent(
    serverId: string,
    envelope: EventEnvelopeDto,
  ): Promise<void> {
    this.logger.debug(
      `Lifecycle event stored: serverId=${serverId}, event=${envelope.event_name}`,
    );
  }
}
