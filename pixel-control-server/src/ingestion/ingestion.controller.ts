import {
  BadRequestException,
  Body,
  Controller,
  Headers,
  HttpCode,
  HttpStatus,
  InternalServerErrorException,
  Post,
  UseFilters,
  UsePipes,
  ValidationPipe,
} from '@nestjs/common';
import {
  ApiBody,
  ApiHeader,
  ApiOperation,
  ApiResponse,
  ApiTags,
} from '@nestjs/swagger';

import { AckResponse, ErrorResponse } from '../common/dto/ack-response.dto';
import { EventEnvelopeDto } from '../common/dto/event-envelope.dto';
import { ConnectivityValidationFilter } from '../common/filters/connectivity-validation.filter';
import { BatchAckResponse } from './services/batch.service';
import { IngestionService } from './ingestion.service';

interface WrappedPluginPayload {
  envelope?: Record<string, unknown>;
  transport?: Record<string, unknown>;
  [key: string]: unknown;
}

@ApiTags('Plugin Events')
@Controller('plugin/events')
@UseFilters(ConnectivityValidationFilter)
export class IngestionController {
  constructor(private readonly ingestionService: IngestionService) {}

  @ApiOperation({
    summary: 'Receive all event categories from plugin',
    description:
      'Unified ingestion endpoint for all plugin event categories: connectivity, lifecycle, combat, player, mode, and batch. ' +
      'Validates, deduplicates (via idempotency_key), and stores each event in the unified Event table. ' +
      'Connectivity events are additionally written to the ConnectivityEvent table for backward compatibility. ' +
      'Auto-registers unknown servers on first event. ' +
      'Accepts both the plugin wrapped format { envelope: {...}, transport: {...} } and flat envelope format for backward compatibility. ' +
      'Batch events (event_category="batch") unpack payload.events and process each inner envelope individually.',
  })
  @ApiHeader({
    name: 'X-Pixel-Server-Login',
    required: true,
    description: 'Dedicated server login sending the event',
  })
  @ApiHeader({
    name: 'X-Pixel-Plugin-Version',
    required: false,
    description: 'Plugin version string (e.g. "1.0.0")',
  })
  @ApiBody({
    type: EventEnvelopeDto,
    description:
      'Standard event envelope. event_category determines routing. ' +
      'Plugin wraps envelopes as { "envelope": {...}, "transport": {...} }; both formats are accepted.',
  })
  @ApiResponse({
    status: 200,
    description:
      'Event accepted. Returns { ack: { status: "accepted" } } or { ack: { status: "accepted", disposition: "duplicate" } } for duplicates. ' +
      'Batch events return { ack: { status: "accepted", batch_size: N, accepted: M, duplicates: D, rejected: R } }.',
  })
  @ApiResponse({
    status: 400,
    description:
      'Rejected — missing X-Pixel-Server-Login header or invalid envelope. ' +
      'Returns { ack: { status: "rejected", code: "missing_server_login"|"invalid_envelope", retryable: false } }.',
  })
  @ApiResponse({
    status: 500,
    description:
      'Internal error. Returns { error: { code: "internal_error", retryable: true, retry_after_seconds: 5 } }.',
  })
  @Post()
  @HttpCode(HttpStatus.OK)
  @UsePipes(new ValidationPipe({ whitelist: true, transform: true }))
  async ingestEvent(
    @Headers('x-pixel-server-login') serverLogin: string | undefined,
    @Headers('x-pixel-plugin-version') pluginVersion: string | undefined,
    @Body() body: WrappedPluginPayload,
  ): Promise<AckResponse | BatchAckResponse | ErrorResponse> {
    if (!serverLogin) {
      throw new BadRequestException({
        ack: {
          status: 'rejected',
          code: 'missing_server_login',
          retryable: false,
        },
      });
    }

    // Support both the plugin's wrapped format { "envelope": {...}, "transport": {...} }
    // and the flat format for backward compatibility with curl smoke tests.
    const envelopeData: Record<string, unknown> =
      body.envelope !== undefined &&
      typeof body.envelope === 'object' &&
      body.envelope !== null
        ? body.envelope
        : body;

    // Validate the envelope using the DTO validator
    const validationPipe = new ValidationPipe({
      whitelist: true,
      transform: true,
      expectedType: EventEnvelopeDto,
    });
    let envelope: EventEnvelopeDto;
    try {
      envelope = (await validationPipe.transform(envelopeData, {
        type: 'body',
        metatype: EventEnvelopeDto,
      })) as EventEnvelopeDto;
    } catch {
      throw new BadRequestException({
        ack: {
          status: 'rejected',
          code: 'invalid_envelope',
          retryable: false,
        },
      });
    }

    const result = await this.ingestionService.ingestEvent(
      serverLogin,
      pluginVersion,
      envelope,
    );

    // If service returned an internal error, throw 500
    if ('error' in result) {
      throw new InternalServerErrorException(result);
    }

    return result;
  }
}
