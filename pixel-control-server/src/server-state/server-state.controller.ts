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
      'Falls back to linked template config if no saved state exists. ' +
      'Returns { state: null, updated_at: null, source: "default" } if neither state nor template exist. ' +
      'The "source" field indicates whether the state came from a saved snapshot ("saved"), ' +
      'a linked config template ("template"), or is the default null state ("default").',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'State returned (may be null if no prior save and no template).' })
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

  // ─── POST apply-template ─────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Apply linked config template as server state',
    description:
      'Takes the linked template config, wraps it in a full state snapshot envelope, ' +
      'and saves it as the server\'s persisted state (upsert). ' +
      'Returns 400 if the server has no linked template.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Template applied as server state.' })
  @ApiResponse({ status: 400, description: 'Server has no linked config template.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Post(':serverLogin/state/apply-template')
  @HttpCode(200)
  applyTemplate(@Param('serverLogin') serverLogin: string) {
    return this.serverStateService.applyTemplate(serverLogin);
  }
}
