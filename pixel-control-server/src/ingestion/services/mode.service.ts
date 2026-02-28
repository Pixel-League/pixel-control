import { Injectable, Logger } from '@nestjs/common';

import { EventEnvelopeDto } from '../../common/dto/event-envelope.dto';

/**
 * Handles mode event category ingestion.
 * P1: placeholder — event is already stored in the unified Event table by IngestionService.
 * P2 will add mode-specific state derivation here.
 */
@Injectable()
export class ModeService {
  private readonly logger = new Logger(ModeService.name);

  async ingestModeEvent(
    serverId: string,
    envelope: EventEnvelopeDto,
  ): Promise<void> {
    this.logger.debug(
      `Mode event stored: serverId=${serverId}, event=${envelope.event_name}`,
    );
  }
}
