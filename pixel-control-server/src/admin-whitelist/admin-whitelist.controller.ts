import {
  Body,
  Controller,
  Delete,
  Get,
  HttpCode,
  Param,
  Post,
} from '@nestjs/common';
import {
  ApiBody,
  ApiOperation,
  ApiParam,
  ApiResponse,
  ApiTags,
} from '@nestjs/swagger';

import { WhitelistAddDto } from '../admin-proxy/dto/admin-action.dto';
import { AdminWhitelistService } from './admin-whitelist.service';

@ApiTags('Admin - Whitelist')
@Controller('servers')
export class AdminWhitelistController {
  constructor(private readonly adminWhitelistService: AdminWhitelistService) {}

  // ─── P5.3 Enable Whitelist ────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Enable server whitelist',
    description: 'Enables the server whitelist, restricting connections to whitelisted players only.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Whitelist enabled successfully.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/whitelist/enable')
  @HttpCode(200)
  enableWhitelist(@Param('serverLogin') serverLogin: string) {
    return this.adminWhitelistService.enableWhitelist(serverLogin);
  }

  // ─── P5.4 Disable Whitelist ───────────────────────────────────────────────

  @ApiOperation({
    summary: 'Disable server whitelist',
    description: 'Disables the server whitelist, allowing all players to connect.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Whitelist disabled successfully.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/whitelist/disable')
  @HttpCode(200)
  disableWhitelist(@Param('serverLogin') serverLogin: string) {
    return this.adminWhitelistService.disableWhitelist(serverLogin);
  }

  // ─── P5.5 Add to Whitelist ────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Add a player to the whitelist',
    description: 'Adds the specified player login to the server whitelist.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiBody({ type: WhitelistAddDto })
  @ApiResponse({ status: 200, description: 'Player added to whitelist successfully.' })
  @ApiResponse({ status: 400, description: 'Invalid or missing target_login.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/whitelist')
  @HttpCode(200)
  addToWhitelist(
    @Param('serverLogin') serverLogin: string,
    @Body() body: WhitelistAddDto,
  ) {
    return this.adminWhitelistService.addToWhitelist(serverLogin, body.target_login);
  }

  // ─── P5.7 List Whitelist ──────────────────────────────────────────────────

  @ApiOperation({
    summary: 'List all whitelisted players',
    description: 'Returns the current server whitelist of allowed player logins.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Whitelist returned.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Get(':serverLogin/whitelist')
  listWhitelist(@Param('serverLogin') serverLogin: string) {
    return this.adminWhitelistService.listWhitelist(serverLogin);
  }

  // ─── P5.8 Clean Whitelist (all) ───────────────────────────────────────────
  //
  // IMPORTANT: This bare DELETE must be declared BEFORE @Delete(':serverLogin/whitelist/:login')
  // so that Fastify registers the exact path match first. Fastify resolves in registration order
  // and will match `DELETE /servers/:serverLogin/whitelist` (no trailing segment) here.

  @ApiOperation({
    summary: 'Clear the entire whitelist',
    description: 'Removes all players from the server whitelist in one operation.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Whitelist cleared successfully.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Delete(':serverLogin/whitelist')
  @HttpCode(200)
  cleanWhitelist(@Param('serverLogin') serverLogin: string) {
    return this.adminWhitelistService.cleanWhitelist(serverLogin);
  }

  // ─── P5.6 Remove from Whitelist ───────────────────────────────────────────

  @ApiOperation({
    summary: 'Remove a player from the whitelist',
    description: 'Removes the specified player login from the server whitelist.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiParam({ name: 'login', description: 'Player login to remove from the whitelist', example: 'player.login.example' })
  @ApiResponse({ status: 200, description: 'Player removed from whitelist successfully.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Delete(':serverLogin/whitelist/:login')
  @HttpCode(200)
  removeFromWhitelist(
    @Param('serverLogin') serverLogin: string,
    @Param('login') login: string,
  ) {
    return this.adminWhitelistService.removeFromWhitelist(serverLogin, login);
  }

  // ─── P5.9 Sync Whitelist ──────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Sync whitelist from file',
    description: 'Triggers ManiaControl to reload the whitelist from its persistent storage file.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Whitelist synced successfully.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/whitelist/sync')
  @HttpCode(200)
  syncWhitelist(@Param('serverLogin') serverLogin: string) {
    return this.adminWhitelistService.syncWhitelist(serverLogin);
  }
}
