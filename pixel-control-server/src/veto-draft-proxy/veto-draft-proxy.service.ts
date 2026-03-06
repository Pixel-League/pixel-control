import {
  BadGatewayException,
  ForbiddenException,
  Injectable,
  Logger,
  NotFoundException,
} from '@nestjs/common';
import { ConfigService } from '@nestjs/config';

import { ServerResolverService } from '../common/services/server-resolver.service';
import { ManiaControlSocketClient } from '../admin-proxy/maniacontrol-socket.client';
import { VetoDraftCommandResponse, VetoDraftStatusResponse } from './dto/veto-draft.dto';

// Plugin response codes that indicate auth errors (403).
const AUTH_ERROR_CODES = new Set([
  'link_auth_missing',
  'link_auth_invalid',
  'link_server_mismatch',
  'admin_command_unauthorized',
]);

interface RawVetoDraftResponse {
  success?: boolean;
  code?: string;
  message?: string;
  details?: Record<string, unknown>;
  status?: Record<string, unknown>;
  communication?: Record<string, unknown>;
  series_targets?: Record<string, unknown>;
  error?: boolean;
}

/**
 * High-level service for proxying VetoDraft commands from the API server
 * to the ManiaControl CommunicationManager socket.
 *
 * KEY DIFFERENCE from AdminProxyService:
 * Each VetoDraft endpoint uses a distinct socket method name
 * (PixelControl.VetoDraft.<methodSuffix>) instead of a single
 * PixelControl.Admin.ExecuteAction method with an action field.
 *
 * Responsibilities:
 * - Resolves server record (404 if not found).
 * - Injects link-auth (server_login + link_bearer token from DB).
 * - Sends the PixelControl.VetoDraft.* command via the socket client.
 * - Maps plugin response codes to appropriate HTTP exceptions.
 */
@Injectable()
export class VetoDraftProxyService {
  private readonly logger = new Logger(VetoDraftProxyService.name);
  private readonly host: string;
  private readonly port: number;
  private readonly password: string;

  constructor(
    private readonly socketClient: ManiaControlSocketClient,
    private readonly serverResolver: ServerResolverService,
    config: ConfigService,
  ) {
    this.host = config.get<string>('MC_SOCKET_HOST') ?? '127.0.0.1';
    this.port = config.get<number>('MC_SOCKET_PORT') ?? 31501;
    this.password = config.get<string>('MC_SOCKET_PASSWORD') ?? '';
  }

  /**
   * Sends a VetoDraft command and returns the plugin response.
   *
   * Builds the socket method as `PixelControl.VetoDraft.<methodSuffix>`.
   * Injects link-auth (server_login + link_bearer token) into the payload.
   * Maps socket and plugin errors to appropriate HTTP exceptions.
   */
  async sendVetoDraftCommand(
    serverLogin: string,
    methodSuffix: string,
    data?: Record<string, unknown>,
  ): Promise<VetoDraftCommandResponse | VetoDraftStatusResponse> {
    // 1. Resolve server (throws 404 if not found).
    const { server } = await this.serverResolver.resolve(serverLogin);

    // 2. Build the PixelControl.VetoDraft.<methodSuffix> payload with injected link-auth.
    const method = `PixelControl.VetoDraft.${methodSuffix}`;
    const payload: Record<string, unknown> = {
      server_login: serverLogin,
      auth: {
        mode: 'link_bearer',
        token: server.linkToken ?? '',
      },
    };
    if (data !== undefined && Object.keys(data).length > 0) {
      payload['parameters'] = data;
    }

    // 3. Send via socket client.
    let socketResult: { error: boolean; data: unknown };
    try {
      socketResult = await this.socketClient.sendCommand(
        this.host,
        this.port,
        this.password,
        method,
        payload,
      );
    } catch (err) {
      this.logger.error(`Socket send failed for VetoDraft.${methodSuffix}: ${String(err)}`);
      throw new BadGatewayException(`Socket communication failed: ${String(err)}`);
    }

    // 4. Handle transport-level errors.
    if (socketResult.error) {
      const errorData = socketResult.data as Record<string, unknown> | undefined;
      const message =
        typeof errorData?.['message'] === 'string' ? errorData['message'] : 'Socket error';
      this.logger.warn(`Socket error for VetoDraft.${methodSuffix}: ${message}`);
      throw new BadGatewayException(`ManiaControl socket unavailable: ${message}`);
    }

    // 5. Parse and validate the plugin response.
    // ManiaControl CommunicationManager wraps responses in CommunicationAnswer: {error, data}.
    const communicationAnswer = socketResult.data as {
      error?: boolean;
      data?: unknown;
    };

    if (communicationAnswer?.error === true) {
      const errorMsg =
        typeof communicationAnswer.data === 'string'
          ? communicationAnswer.data
          : 'Plugin communication error';
      throw new BadGatewayException(
        `Plugin communication error for VetoDraft.${methodSuffix}: ${errorMsg}`,
      );
    }

    const raw = (communicationAnswer?.data ?? communicationAnswer) as RawVetoDraftResponse;

    // 6. Map auth error codes to HTTP exceptions.
    if (!raw?.success && raw?.code && AUTH_ERROR_CODES.has(raw.code)) {
      throw new ForbiddenException(
        `VetoDraft.${methodSuffix} rejected: ${raw.message ?? raw.code}`,
      );
    }

    return {
      success: raw?.success ?? false,
      code: raw?.code ?? 'unknown',
      message: raw?.message ?? '',
      details: raw?.details,
      status: raw?.status as VetoDraftStatusResponse['status'],
      communication: raw?.communication as VetoDraftStatusResponse['communication'],
      series_targets: raw?.series_targets,
    };
  }

  /**
   * Convenience method for querying the VetoDraft status (P4.1).
   * Same as sendVetoDraftCommand with 'Status' — named explicitly for clarity.
   */
  async queryVetoDraftStatus(serverLogin: string): Promise<VetoDraftStatusResponse> {
    const result = await this.sendVetoDraftCommand(serverLogin, 'Status');
    return result as VetoDraftStatusResponse;
  }
}
