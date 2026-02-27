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
import { ConnectivityService } from './connectivity.service';

interface WrappedPluginPayload {
  envelope?: Record<string, unknown>;
  transport?: Record<string, unknown>;
  [key: string]: unknown;
}

@ApiTags('Plugin Events')
@Controller('plugin/events')
@UseFilters(ConnectivityValidationFilter)
export class ConnectivityController {
  constructor(private readonly connectivityService: ConnectivityService) {}

  @ApiOperation({
    summary: 'Receive connectivity events from plugin',
    description:
      'Ingests plugin_registration and plugin_heartbeat events. Updates server state (last heartbeat, plugin version, online status). Supports idempotent delivery via idempotency_key -- duplicates are accepted with disposition "duplicate". Auto-registers unknown servers on first event. Accepts both the plugin wrapped format { envelope: {...}, transport: {...} } and flat envelope format for backward compatibility.',
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
      'Standard event envelope with connectivity payload (plugin_registration or plugin_heartbeat). Plugin wraps envelopes as { "envelope": {...}, "transport": {...} }; both formats are accepted.',
  })
  @ApiResponse({
    status: 200,
    description:
      'Event accepted. Returns { ack: { status: "accepted" } } or { ack: { status: "accepted", disposition: "duplicate" } } for duplicates.',
  })
  @ApiResponse({
    status: 400,
    description:
      'Rejected -- missing X-Pixel-Server-Login header or invalid envelope. Returns { ack: { status: "rejected", code: "missing_server_login"|"invalid_envelope", retryable: false } }.',
  })
  @ApiResponse({
    status: 500,
    description:
      'Internal error. Returns { error: { code: "internal_error", retryable: true, retry_after_seconds: 5 } }.',
  })
  @Post()
  @HttpCode(HttpStatus.OK)
  @UsePipes(new ValidationPipe({ whitelist: true, transform: true }))
  async ingestConnectivityEvent(
    @Headers('x-pixel-server-login') serverLogin: string | undefined,
    @Headers('x-pixel-plugin-version') pluginVersion: string | undefined,
    @Body() body: WrappedPluginPayload,
  ): Promise<AckResponse | ErrorResponse> {
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
      body.envelope !== undefined && typeof body.envelope === 'object' && body.envelope !== null
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

    const result = await this.connectivityService.ingestEvent(
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
