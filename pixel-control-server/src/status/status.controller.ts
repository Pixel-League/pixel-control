import {
  Controller,
  Get,
  NotFoundException,
  Param,
} from '@nestjs/common';
import {
  ApiOperation,
  ApiParam,
  ApiResponse,
  ApiTags,
} from '@nestjs/swagger';

import { ServerHealthResponse, ServerStatusResponse, StatusService } from './status.service';

@ApiTags('Server Status')
@Controller('servers')
export class StatusController {
  constructor(private readonly statusService: StatusService) {}

  @ApiOperation({
    summary: 'Get server status',
    description:
      'Returns the current status of a connected server, including online status, game mode, ' +
      'plugin version, player counts (from latest heartbeat), and event counts by category. ' +
      'Returns 404 if the server has never connected.',
  })
  @ApiParam({
    name: 'serverLogin',
    description: 'The dedicated server login (unique identifier)',
    example: 'pixel-elite-1.server.local',
  })
  @ApiResponse({
    status: 200,
    description:
      'Server status returned. Fields: server_login, server_name, linked, online, game_mode, ' +
      'title_id, plugin_version, last_heartbeat (ISO8601), player_counts { active, total, spectators }, ' +
      'event_counts { total, by_category { connectivity, lifecycle, combat, player, mode } }.',
  })
  @ApiResponse({
    status: 404,
    description: 'Server not found — server has never connected or been registered.',
  })
  @Get(':serverLogin/status')
  async getServerStatus(
    @Param('serverLogin') serverLogin: string,
  ): Promise<ServerStatusResponse> {
    try {
      return await this.statusService.getServerStatus(serverLogin);
    } catch (err) {
      if (err instanceof NotFoundException) {
        throw err;
      }
      throw new NotFoundException(`Server '${serverLogin}' not found`);
    }
  }

  @ApiOperation({
    summary: 'Get server plugin health',
    description:
      'Returns plugin health metrics extracted from the latest connectivity heartbeat event. ' +
      'Includes queue depth, retry configuration, outage status, and connectivity event counts. ' +
      'Returns 404 if the server has never connected.',
  })
  @ApiParam({
    name: 'serverLogin',
    description: 'The dedicated server login (unique identifier)',
    example: 'pixel-elite-1.server.local',
  })
  @ApiResponse({
    status: 200,
    description:
      'Plugin health returned. Fields: server_login, online, ' +
      'plugin_health { queue { depth, max_size, high_watermark, dropped_on_capacity, dropped_on_identity_validation, recovery_flush_pending }, ' +
      'retry { max_retry_attempts, retry_backoff_ms, dispatch_batch_size }, ' +
      'outage { active, started_at, failure_count, last_error_code, recovery_flush_pending } }, ' +
      'connectivity_metrics { total_connectivity_events, last_registration_at, last_heartbeat_at, heartbeat_count, registration_count }.',
  })
  @ApiResponse({
    status: 404,
    description: 'Server not found — server has never connected or been registered.',
  })
  @Get(':serverLogin/status/health')
  async getServerHealth(
    @Param('serverLogin') serverLogin: string,
  ): Promise<ServerHealthResponse> {
    try {
      return await this.statusService.getServerHealth(serverLogin);
    } catch (err) {
      if (err instanceof NotFoundException) {
        throw err;
      }
      throw new NotFoundException(`Server '${serverLogin}' not found`);
    }
  }
}
