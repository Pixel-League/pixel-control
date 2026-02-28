import { Injectable, Logger } from '@nestjs/common';

import { EventEnvelopeDto } from '../../common/dto/event-envelope.dto';

/**
 * Handles player event category ingestion.
 * P1: placeholder — event is already stored in the unified Event table by IngestionService.
 * P2 will add player session tracking derivation here.
 */
@Injectable()
export class PlayerService {
  private readonly logger = new Logger(PlayerService.name);

  async ingestPlayerEvent(
    serverId: string,
    envelope: EventEnvelopeDto,
  ): Promise<void> {
    this.logger.debug(
      `Player event stored: serverId=${serverId}, event=${envelope.event_name}`,
    );
  }
}
