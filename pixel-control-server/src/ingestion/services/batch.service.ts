import { Injectable, Logger } from '@nestjs/common';

import { AckResponse } from '../../common/dto/ack-response.dto';
import { EventEnvelopeDto } from '../../common/dto/event-envelope.dto';

export interface BatchAckDetail {
  status: 'accepted';
  batch_size: number;
  accepted: number;
  duplicates: number;
  rejected: number;
}

export interface BatchAckResponse {
  ack: BatchAckDetail;
}

type SingleIngestFn = (envelope: EventEnvelopeDto) => Promise<AckResponse | { error: unknown }>;

/**
 * Handles batch event ingestion.
 * Batch events have event_category "batch" and contain an array of individual
 * event envelopes in payload.events. Each inner envelope is processed individually.
 *
 * NOTE: The current plugin does NOT send batch events (it sends events individually).
 * This service is forward-compatible scaffolding for a future batch mechanism.
 */
@Injectable()
export class BatchService {
  private readonly logger = new Logger(BatchService.name);

  async ingestBatchEvent(
    batchEnvelope: EventEnvelopeDto,
    ingestSingleFn: SingleIngestFn,
  ): Promise<BatchAckResponse> {
    const payload = batchEnvelope.payload as Record<string, unknown>;
    const events = Array.isArray(payload['events'])
      ? (payload['events'] as Record<string, unknown>[])
      : [];

    const batchSize = events.length;

    if (batchSize === 0) {
      this.logger.warn(
        `Empty batch received: event=${batchEnvelope.event_name}`,
      );
      return {
        ack: {
          status: 'accepted',
          batch_size: 0,
          accepted: 0,
          duplicates: 0,
          rejected: 0,
        },
      };
    }

    let accepted = 0;
    let duplicates = 0;
    let rejected = 0;

    for (const rawEnvelope of events) {
      // Cast inner envelope — it should conform to EventEnvelopeDto shape
      const innerEnvelope = rawEnvelope as unknown as EventEnvelopeDto;
      try {
        const result = await ingestSingleFn(innerEnvelope);
        if ('error' in result) {
          rejected++;
          this.logger.warn(
            `Batch inner event rejected (internal error): event_id=${innerEnvelope.event_id}`,
          );
        } else if (result.ack.disposition === 'duplicate') {
          duplicates++;
        } else {
          accepted++;
        }
      } catch (err) {
        rejected++;
        this.logger.warn(
          `Batch inner event threw: event_id=${innerEnvelope.event_id ?? 'unknown'}, err=${String(err)}`,
        );
      }
    }

    this.logger.debug(
      `Batch processed: total=${batchSize}, accepted=${accepted}, duplicates=${duplicates}, rejected=${rejected}`,
    );

    return {
      ack: {
        status: 'accepted',
        batch_size: batchSize,
        accepted,
        duplicates,
        rejected,
      },
    };
  }
}
