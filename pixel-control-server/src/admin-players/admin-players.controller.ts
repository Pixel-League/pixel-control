import {
  Body,
  Controller,
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

import { ForceTeamDto } from '../admin-proxy/dto/admin-action.dto';
import { AdminPlayersService } from './admin-players.service';

@ApiTags('Admin - Players')
@Controller('servers')
export class AdminPlayersController {
  constructor(private readonly adminPlayersService: AdminPlayersService) {}

  // ─── P4.6 Force Team ──────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Force a player to a specific team',
    description:
      'Forces the specified player into Team A or Team B. ' +
      'Accepted team values: "team_a", "team_b", "0", "1", "red", "blue", "a", "b".',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiParam({ name: 'login', description: 'Player login to force', example: 'player.login.example' })
  @ApiBody({ type: ForceTeamDto })
  @ApiResponse({ status: 200, description: 'Player forced to team successfully.' })
  @ApiResponse({ status: 400, description: 'Invalid or missing team parameter.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/players/:login/force-team')
  @HttpCode(200)
  forceTeam(
    @Param('serverLogin') serverLogin: string,
    @Param('login') playerLogin: string,
    @Body() body: ForceTeamDto,
  ) {
    return this.adminPlayersService.forceTeam(serverLogin, playerLogin, body.team);
  }

  // ─── P4.7 Force Play ──────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Force a player out of spectator mode',
    description:
      'Moves the specified player from spectator to player slot. ' +
      'Equivalent to forceSpectator($login, 0) in ManiaControl.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiParam({ name: 'login', description: 'Player login to force out of spectator', example: 'player.login.example' })
  @ApiResponse({ status: 200, description: 'Player forced to play successfully.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/players/:login/force-play')
  @HttpCode(200)
  forcePlay(
    @Param('serverLogin') serverLogin: string,
    @Param('login') playerLogin: string,
  ) {
    return this.adminPlayersService.forcePlay(serverLogin, playerLogin);
  }

  // ─── P4.8 Force Spec ──────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Force a player into spectator mode',
    description:
      'Moves the specified player to the spectator slot. ' +
      'Equivalent to forceSpectator($login, 1) in ManiaControl.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiParam({ name: 'login', description: 'Player login to force into spectator', example: 'player.login.example' })
  @ApiResponse({ status: 200, description: 'Player forced to spectator successfully.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/players/:login/force-spec')
  @HttpCode(200)
  forceSpec(
    @Param('serverLogin') serverLogin: string,
    @Param('login') playerLogin: string,
  ) {
    return this.adminPlayersService.forceSpec(serverLogin, playerLogin);
  }
}
