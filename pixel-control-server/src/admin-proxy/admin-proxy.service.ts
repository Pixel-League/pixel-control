import {
  BadGatewayException,
  BadRequestException,
  ForbiddenException,
  Injectable,
  Logger,
  NotFoundException,
} from '@nestjs/common';
import { ConfigService } from '@nestjs/config';

import { ServerResolverService } from '../common/services/server-resolver.service';
import { AdminActionResponse } from './dto/admin-action.dto';
import { ManiaControlSocketClient } from './maniacontrol-socket.client';

// Plugin response codes that indicate client-side errors (4xx).
const CLIENT_ERROR_CODES = new Set([
  'map_not_found',
  'invalid_parameter',
  'invalid_map_uid',
  'invalid_mx_id',
  'invalid_best_of',
  'invalid_team',
  'invalid_score',
  'invalid_seconds',
]);

// Plugin response codes that indicate auth errors (403).
const AUTH_ERROR_CODES = new Set([
  'link_auth_missing',
  'link_auth_invalid',
  'link_server_mismatch',
  'admin_command_unauthorized',
]);

// Plugin response codes that indicate not-found (404).
const NOT_FOUND_CODES = new Set(['action_not_found']);

interface RawPluginResponse {
  action_name?: string;
  success?: boolean;
  code?: string;
  message?: string;
  details?: Record<string, unknown>;
  error?: boolean;
}

/**
 * High-level service for proxying admin commands from the API server
 * to the ManiaControl CommunicationManager socket.
 *
 * Responsibilities:
 * - Resolves server record (404 if not found).
 * - Injects link-auth (server_login + link_bearer token from DB).
 * - Sends the PixelControl.Admin.ExecuteAction command via the socket client.
 * - Maps plugin response codes to appropriate HTTP exceptions.
 */
@Injectable()
export class AdminProxyService {
  private readonly logger = new Logger(AdminProxyService.name);
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
   * Executes an admin action and throws on failure (for write/command endpoints).
   */
  async executeAction(
    serverLogin: string,
    actionName: string,
    parameters?: Record<string, unknown>,
  ): Promise<AdminActionResponse> {
    return this.sendAction(serverLogin, actionName, parameters, true);
  }

  /**
   * Queries an admin action and returns the result without throwing on plugin-level failure.
   * Used for GET endpoints (match.bo.get, match.maps.get, match.score.get).
   */
  async queryAction(
    serverLogin: string,
    actionName: string,
    parameters?: Record<string, unknown>,
  ): Promise<AdminActionResponse> {
    return this.sendAction(serverLogin, actionName, parameters, false);
  }

  private async sendAction(
    serverLogin: string,
    actionName: string,
    parameters: Record<string, unknown> | undefined,
    throwOnPluginFailure: boolean,
  ): Promise<AdminActionResponse> {
    // 1. Resolve server (throws 404 if not found).
    const { server } = await this.serverResolver.resolve(serverLogin);

    // 2. Build the PixelControl.Admin.ExecuteAction payload with injected link-auth.
    const payload: Record<string, unknown> = {
      action: actionName,
      server_login: serverLogin,
      auth: {
        mode: 'link_bearer',
        token: server.linkToken ?? '',
      },
    };
    if (parameters !== undefined && Object.keys(parameters).length > 0) {
      payload['parameters'] = parameters;
    }

    // 3. Send via socket client.
    let socketResult: { error: boolean; data: unknown };
    try {
      socketResult = await this.socketClient.sendCommand(
        this.host,
        this.port,
        this.password,
        'PixelControl.Admin.ExecuteAction',
        payload,
      );
    } catch (err) {
      this.logger.error(`Socket send failed for action '${actionName}': ${String(err)}`);
      throw new BadGatewayException(`Socket communication failed: ${String(err)}`);
    }

    // 4. Handle transport-level errors.
    if (socketResult.error) {
      const errorData = socketResult.data as Record<string, unknown> | undefined;
      const code = typeof errorData?.['code'] === 'string' ? errorData['code'] : 'socket_error';
      const message =
        typeof errorData?.['message'] === 'string' ? errorData['message'] : 'Socket error';
      this.logger.warn(`Socket error for action '${actionName}': ${code} — ${message}`);
      throw new BadGatewayException(`ManiaControl socket unavailable: ${message}`);
    }

    // 5. Parse and validate the plugin response.
    const raw = socketResult.data as RawPluginResponse;

    if (raw?.error === true) {
      throw new BadGatewayException(
        `Plugin communication error for action '${actionName}'`,
      );
    }

    const response: AdminActionResponse = {
      action_name: raw?.action_name ?? actionName,
      success: raw?.success ?? false,
      code: raw?.code ?? 'unknown',
      message: raw?.message ?? '',
      details: raw?.details,
    };

    // 6. Map plugin error codes to HTTP exceptions (only for execute, not query).
    if (throwOnPluginFailure && !response.success) {
      this.mapPluginErrorToHttpException(response.code, response.message, actionName);
    }

    return response;
  }

  private mapPluginErrorToHttpException(
    code: string,
    message: string,
    actionName: string,
  ): never {
    if (AUTH_ERROR_CODES.has(code)) {
      throw new ForbiddenException(`Admin action '${actionName}' rejected: ${message || code}`);
    }
    if (NOT_FOUND_CODES.has(code)) {
      throw new NotFoundException(`Admin action '${actionName}' not found: ${message || code}`);
    }
    if (CLIENT_ERROR_CODES.has(code)) {
      throw new BadRequestException(`Admin action '${actionName}' failed: ${message || code}`);
    }
    // Default: bad gateway for unknown plugin errors.
    throw new BadGatewayException(`Admin action '${actionName}' failed: ${message || code}`);
  }
}
