import { Body, Controller, Delete, Get, Param, Post, Put, Query } from '@nestjs/common';
import {
  ApiBody,
  ApiOperation,
  ApiParam,
  ApiQuery,
  ApiResponse,
  ApiTags,
} from '@nestjs/swagger';

import { LinkRegistrationDto } from '../common/dto/link-registration.dto';
import { LinkTokenDto } from '../common/dto/link-token.dto';
import { ServersQueryDto, ServerStatusFilter } from '../common/dto/servers-query.dto';
import {
  AccessResult,
  AuthStateResult,
  DeleteServerResult,
  GenerateTokenResult,
  LinkService,
  RegisterServerResult,
  ServerListItem,
} from './link.service';

@ApiTags('Link')
@Controller()
export class LinkController {
  constructor(private readonly linkService: LinkService) {}

  @ApiOperation({
    summary: 'Register or update a server identity',
    description:
      'Creates the server record in the API database on first call. Subsequent calls update server_name, game_mode, and title_id. A link token is generated automatically on first registration.',
  })
  @ApiParam({
    name: 'serverLogin',
    description: 'Dedicated server login (unique identifier of the game server)',
  })
  @ApiBody({
    type: LinkRegistrationDto,
    description: 'Optional server metadata to register or update',
  })
  @ApiResponse({
    status: 200,
    description:
      'Server registered or updated successfully. Returns server_login, registered flag, and link_token (only on first registration).',
  })
  @Put('servers/:serverLogin/link/registration')
  registerServer(
    @Param('serverLogin') serverLogin: string,
    @Body() dto: LinkRegistrationDto,
  ): Promise<RegisterServerResult> {
    return this.linkService.registerServer(serverLogin, dto);
  }

  @ApiOperation({
    summary: 'Generate or rotate the link token',
    description:
      'Returns the existing link token, or generates a new one if rotate=true or no token exists. The link token is the shared secret between API and plugin for link_bearer auth.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Dedicated server login' })
  @ApiBody({
    type: LinkTokenDto,
    description: 'Set rotate=true to force token rotation',
  })
  @ApiResponse({
    status: 200,
    description: 'Returns server_login, link_token, and rotated flag.',
  })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Post('servers/:serverLogin/link/token')
  generateToken(
    @Param('serverLogin') serverLogin: string,
    @Body() dto: LinkTokenDto,
  ): Promise<GenerateTokenResult> {
    return this.linkService.generateToken(serverLogin, dto);
  }

  @ApiOperation({
    summary: 'Check if server is linked and auth is valid',
    description:
      'Returns the current link status, last heartbeat timestamp, plugin version, and computed online status. A server is online if its last heartbeat was received within the configured threshold (default 360s).',
  })
  @ApiParam({ name: 'serverLogin', description: 'Dedicated server login' })
  @ApiResponse({
    status: 200,
    description:
      'Returns server_login, linked, last_heartbeat, plugin_version, and online.',
  })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Get('servers/:serverLogin/link/auth-state')
  getAuthState(
    @Param('serverLogin') serverLogin: string,
  ): Promise<AuthStateResult> {
    return this.linkService.getAuthState(serverLogin);
  }

  @ApiOperation({
    summary: 'Check server access and permissions',
    description:
      'Returns whether the server has access granted (currently equivalent to linked status), along with link and online state. Future tiers may add granular permission checks.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Dedicated server login' })
  @ApiResponse({
    status: 200,
    description: 'Returns server_login, access_granted, linked, and online.',
  })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Get('servers/:serverLogin/link/access')
  checkAccess(
    @Param('serverLogin') serverLogin: string,
  ): Promise<AccessResult> {
    return this.linkService.checkAccess(serverLogin);
  }

  @ApiOperation({
    summary: 'Delete a registered server',
    description:
      'Permanently removes a server and all its associated connectivity events from the database. This unlinks the server and clears all stored telemetry.',
  })
  @ApiParam({
    name: 'serverLogin',
    description: 'Dedicated server login to delete',
  })
  @ApiResponse({
    status: 200,
    description: 'Server deleted successfully. Returns server_login and deleted flag.',
  })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Delete('servers/:serverLogin')
  deleteServer(
    @Param('serverLogin') serverLogin: string,
  ): Promise<DeleteServerResult> {
    return this.linkService.deleteServer(serverLogin);
  }

  @ApiOperation({
    summary: 'List all registered servers',
    description:
      'Returns an array of all registered servers with their link status, online state, and metadata. Online status is dynamically computed from heartbeat recency. Filter by status query parameter.',
  })
  @ApiQuery({
    name: 'status',
    required: false,
    enum: ServerStatusFilter,
    description: 'Filter servers: all (default), linked (only linked), offline (only offline)',
  })
  @ApiResponse({
    status: 200,
    description:
      'Array of server summaries with server_login, server_name, linked, online, last_heartbeat, plugin_version, game_mode, and title_id.',
  })
  @Get('servers')
  listServers(@Query() query: ServersQueryDto): Promise<ServerListItem[]> {
    return this.linkService.listServers(query.status);
  }
}
