import {
  Body,
  Controller,
  Delete,
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

import { AuthGrantDto } from '../admin-proxy/dto/admin-action.dto';
import { AdminAuthService } from './admin-auth.service';

@ApiTags('Admin - Auth')
@Controller('servers')
export class AdminAuthController {
  constructor(private readonly adminAuthService: AdminAuthService) {}

  // ─── P5.1 Grant Auth ─────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Grant auth level to a player',
    description:
      'Grants the specified auth level ("player", "moderator", "admin", "superadmin") to the given player login via ManiaControl\'s AuthenticationManager.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiParam({ name: 'login', description: 'Player login to grant auth to', example: 'player.login.example' })
  @ApiBody({ type: AuthGrantDto })
  @ApiResponse({ status: 200, description: 'Auth level granted successfully.' })
  @ApiResponse({ status: 400, description: 'Invalid or missing auth_level.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/players/:login/auth')
  @HttpCode(200)
  grantAuth(
    @Param('serverLogin') serverLogin: string,
    @Param('login') playerLogin: string,
    @Body() body: AuthGrantDto,
  ) {
    return this.adminAuthService.grantAuth(serverLogin, playerLogin, body.auth_level);
  }

  // ─── P5.2 Revoke Auth ────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Revoke auth level from a player',
    description:
      'Revokes any elevated auth level from the specified player login, resetting them to the base "player" level.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiParam({ name: 'login', description: 'Player login to revoke auth from', example: 'player.login.example' })
  @ApiResponse({ status: 200, description: 'Auth revoked successfully.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Delete(':serverLogin/players/:login/auth')
  @HttpCode(200)
  revokeAuth(
    @Param('serverLogin') serverLogin: string,
    @Param('login') playerLogin: string,
  ) {
    return this.adminAuthService.revokeAuth(serverLogin, playerLogin);
  }
}
