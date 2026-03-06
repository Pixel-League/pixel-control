import {
  Body,
  Controller,
  Get,
  Headers,
  HttpCode,
  Param,
  Post,
} from '@nestjs/common';
import {
  ApiBody,
  ApiHeader,
  ApiOperation,
  ApiParam,
  ApiResponse,
  ApiTags,
} from '@nestjs/swagger';

import { ServerStateSnapshotDto, GetStateResponse, SaveStateResponse } from './dto/server-state.dto';
import { ServerStateService } from './server-state.service';

@ApiTags('Server State')
@Controller('servers')
export class ServerStateController {
  constructor(private readonly serverStateService: ServerStateService) {}

  // ─── GET state ───────────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Get persisted plugin state snapshot',
    description:
      'Returns the most recently saved plugin state snapshot for the server. ' +
      'Returns { state: null, updated_at: null } if no snapshot has been saved yet. ' +
      'Always returns 200 — null state is not an error.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'State returned (may be null if no prior save).' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Get(':serverLogin/state')
  @HttpCode(200)
  getState(@Param('serverLogin') serverLogin: string): Promise<GetStateResponse> {
    return this.serverStateService.getState(serverLogin);
  }

  // ─── POST state ──────────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Save plugin state snapshot',
    description:
      'Persists the plugin state snapshot for the server (upsert). ' +
      'Requires a valid link_bearer token in the Authorization header. ' +
      'The snapshot replaces any previously saved state.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiHeader({
    name: 'Authorization',
    description: 'Bearer <link_token> — link bearer token for the server',
    required: true,
  })
  @ApiBody({ type: ServerStateSnapshotDto })
  @ApiResponse({ status: 200, description: 'State saved successfully. Returns { saved: true, updated_at: <iso> }.' })
  @ApiResponse({ status: 400, description: 'Invalid or missing snapshot body.' })
  @ApiResponse({ status: 403, description: 'Invalid or missing link bearer token.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Post(':serverLogin/state')
  @HttpCode(200)
  saveState(
    @Param('serverLogin') serverLogin: string,
    @Headers('authorization') authorizationHeader: string | undefined,
    @Body() body: ServerStateSnapshotDto,
  ): Promise<SaveStateResponse> {
    // Extract "Bearer <token>" -> "<token>"
    const bearerToken = authorizationHeader?.startsWith('Bearer ')
      ? authorizationHeader.slice(7)
      : authorizationHeader;

    return this.serverStateService.saveState(serverLogin, body, bearerToken);
  }
}
