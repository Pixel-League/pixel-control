import { Controller, Get, NotFoundException, Param, Query } from '@nestjs/common';
import {
  ApiHeader,
  ApiOperation,
  ApiParam,
  ApiQuery,
  ApiResponse,
  ApiTags,
} from '@nestjs/swagger';

import { PaginationQueryDto } from '../common/dto/query-params.dto';
import { PlayersReadService } from './players-read.service';

@ApiTags('Players')
@Controller('servers')
export class PlayersReadController {
  constructor(private readonly playersService: PlayersReadService) {}

  @ApiOperation({
    summary: 'Get current player list',
    description:
      'Returns the current de-duplicated player list for a server, reconstructed from the latest ' +
      'player events per unique login. Players with a disconnect event as their latest event are ' +
      'included with is_connected=false. Ordered by last activity (newest first). ' +
      'Supports pagination via limit/offset.',
  })
  @ApiParam({
    name: 'serverLogin',
    description: 'The dedicated server login (unique identifier)',
    example: 'pixel-elite-1.server.local',
  })
  @ApiQuery({ name: 'limit', required: false, description: 'Max items to return (1–200, default 50)', example: 50 })
  @ApiQuery({ name: 'offset', required: false, description: 'Items to skip (default 0)', example: 0 })
  @ApiResponse({
    status: 200,
    description:
      'Player list returned. Fields: data (array of player state objects), pagination { total, limit, offset }. ' +
      'Each player: login, nickname, team_id, is_spectator, is_connected, has_joined_game, auth_level, auth_name, ' +
      'connectivity_state, readiness_state, eligibility_state, last_updated (ISO8601).',
  })
  @ApiResponse({
    status: 404,
    description: 'Server not found.',
  })
  @Get(':serverLogin/players')
  async getPlayers(
    @Param('serverLogin') serverLogin: string,
    @Query() query: PaginationQueryDto,
  ) {
    return this.playersService.getPlayers(
      serverLogin,
      query.limit ?? 50,
      query.offset ?? 0,
    );
  }

  @ApiOperation({
    summary: 'Get single player state',
    description:
      'Returns the full state of a specific player on a server, derived from the most recent ' +
      'player event for that player login. Includes extended fields: permission_signals, roster_state, ' +
      'reconnect_continuity, side_change, constraint_signals, and the source event_id.',
  })
  @ApiParam({
    name: 'serverLogin',
    description: 'The dedicated server login (unique identifier)',
    example: 'pixel-elite-1.server.local',
  })
  @ApiParam({
    name: 'login',
    description: 'The player login (unique ManiaPlanet account identifier)',
    example: 'player1',
  })
  @ApiResponse({
    status: 200,
    description:
      'Player state returned. Includes all base fields plus: permission_signals, roster_state, ' +
      'reconnect_continuity, side_change, constraint_signals, last_event_id, last_updated.',
  })
  @ApiResponse({
    status: 404,
    description: 'Server not found or player has no events on this server.',
  })
  @Get(':serverLogin/players/:login')
  async getPlayer(
    @Param('serverLogin') serverLogin: string,
    @Param('login') login: string,
  ) {
    try {
      return await this.playersService.getPlayer(serverLogin, login);
    } catch (err) {
      if (err instanceof NotFoundException) {
        throw err;
      }
      throw new NotFoundException(`Player '${login}' not found on server '${serverLogin}'`);
    }
  }
}
