import {
  Controller,
  Get,
  Param,
  ParseIntPipe,
  Query,
} from '@nestjs/common';
import {
  ApiOperation,
  ApiParam,
  ApiQuery,
  ApiResponse,
  ApiTags,
} from '@nestjs/swagger';

import { PaginatedTimeRangeQueryDto, PaginationQueryDto } from '../common/dto/query-params.dto';
import {
  EliteStatsReadService,
  EliteClutchTurnRef,
  EliteTurnDetailResponse,
  EliteTurnListResponse,
  PlayerClutchStatsResponse,
  PlayerEliteTurnHistoryResponse,
} from './elite-stats-read.service';

@ApiTags('Stats - Elite')
@Controller('servers')
export class EliteStatsReadController {
  constructor(private readonly eliteStatsService: EliteStatsReadService) {}

  // ---------------------------------------------------------------------------
  // GET :serverLogin/stats/combat/turns
  // ---------------------------------------------------------------------------

  @ApiOperation({
    summary: 'List Elite turn summaries',
    description:
      'Returns paginated Elite turn summaries for the server. Each entry contains the full turn context: ' +
      'attacker/defender logins, outcome, duration, per-player combat stats (kills, deaths, hits, shots, misses, ' +
      'rocket_hits), map context, and clutch detection result. ' +
      'Turns are ordered most-recent first. Supports optional time-range filtering via since/until (ISO8601).',
  })
  @ApiParam({
    name: 'serverLogin',
    description: 'The dedicated server login (unique identifier)',
    example: 'pixel-elite-1.server.local',
  })
  @ApiQuery({ name: 'limit', required: false, description: 'Max turns to return (1–200, default 50)', example: 50 })
  @ApiQuery({ name: 'offset', required: false, description: 'Turns to skip (default 0)', example: 0 })
  @ApiQuery({ name: 'since', required: false, description: 'Filter turns recorded after this ISO8601 timestamp', example: '2026-03-01T09:00:00Z' })
  @ApiQuery({ name: 'until', required: false, description: 'Filter turns recorded before this ISO8601 timestamp', example: '2026-03-01T10:00:00Z' })
  @ApiResponse({
    status: 200,
    description:
      'Paginated list of elite turn summaries. Fields per turn: event_kind, turn_number, attacker_login, ' +
      'defender_logins, attacker_team_id, outcome, duration_seconds, defense_success, per_player_stats, ' +
      'map_uid, map_name, clutch { is_clutch, clutch_player_login, alive_defenders_at_end, total_defenders }.',
  })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Get(':serverLogin/stats/combat/turns')
  async getEliteTurns(
    @Param('serverLogin') serverLogin: string,
    @Query() query: PaginatedTimeRangeQueryDto,
  ): Promise<EliteTurnListResponse> {
    return this.eliteStatsService.getEliteTurns(
      serverLogin,
      query.limit ?? 50,
      query.offset ?? 0,
      query.since,
      query.until,
    );
  }

  // ---------------------------------------------------------------------------
  // GET :serverLogin/stats/combat/turns/:turnNumber
  // ---------------------------------------------------------------------------

  @ApiOperation({
    summary: 'Get a single Elite turn by turn number',
    description:
      'Returns the full turn summary for the given turn number on this server. ' +
      'Turn numbers are monotonically incremented per-server-session by the plugin. ' +
      'If the server restarts the counter resets; the most recent event with the requested ' +
      'turn number is returned. Returns 404 if the turn number has no recorded event.',
  })
  @ApiParam({
    name: 'serverLogin',
    description: 'The dedicated server login (unique identifier)',
    example: 'pixel-elite-1.server.local',
  })
  @ApiParam({
    name: 'turnNumber',
    description: 'The Elite turn number (integer, 1-based)',
    example: 3,
  })
  @ApiResponse({
    status: 200,
    description:
      'Full turn summary with additional fields: server_login, event_id, recorded_at (ISO8601).',
  })
  @ApiResponse({ status: 404, description: 'Server not found or turn number not recorded.' })
  @Get(':serverLogin/stats/combat/turns/:turnNumber')
  async getEliteTurnByNumber(
    @Param('serverLogin') serverLogin: string,
    @Param('turnNumber', ParseIntPipe) turnNumber: number,
  ): Promise<EliteTurnDetailResponse> {
    return this.eliteStatsService.getEliteTurnByNumber(serverLogin, turnNumber);
  }

  // ---------------------------------------------------------------------------
  // GET :serverLogin/stats/combat/players/:login/clutches
  // ---------------------------------------------------------------------------

  @ApiOperation({
    summary: 'Get clutch statistics for a player',
    description:
      'Returns clutch statistics for the given player across all recorded Elite turn summaries on this server. ' +
      'A clutch is defined as: a single remaining defender wins the round (defense_success=true, aliveCount=1, ' +
      'totalDefenders>1). Returns clutch_count, total_defense_rounds the player participated in, ' +
      'clutch_rate (clutch_count / total_defense_rounds), and the list of clutch turns.',
  })
  @ApiParam({
    name: 'serverLogin',
    description: 'The dedicated server login (unique identifier)',
    example: 'pixel-elite-1.server.local',
  })
  @ApiParam({
    name: 'login',
    description: 'The player login to query clutch stats for',
    example: 'player1.ubisoft.com',
  })
  @ApiResponse({
    status: 200,
    description:
      'Clutch stats: server_login, player_login, clutch_count, total_defense_rounds, clutch_rate, ' +
      'clutch_turns [ { turn_number, map_uid, map_name, recorded_at, defender_logins, ' +
      'alive_defenders_at_end, total_defenders, outcome } ].',
  })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Get(':serverLogin/stats/combat/players/:login/clutches')
  async getPlayerClutchStats(
    @Param('serverLogin') serverLogin: string,
    @Param('login') playerLogin: string,
  ): Promise<PlayerClutchStatsResponse> {
    return this.eliteStatsService.getPlayerClutchStats(serverLogin, playerLogin);
  }

  // ---------------------------------------------------------------------------
  // GET :serverLogin/stats/combat/players/:login/turns
  // ---------------------------------------------------------------------------

  @ApiOperation({
    summary: 'Get per-turn Elite history for a player',
    description:
      'Returns a paginated list of Elite turn summaries in which the given player participated ' +
      '(either as attacker or defender). Each entry includes the player\'s per-turn stats ' +
      '(kills, deaths, hits, shots, misses, rocket_hits), their role (attacker/defender), ' +
      'the round outcome, and clutch info. Ordered most-recent first.',
  })
  @ApiParam({
    name: 'serverLogin',
    description: 'The dedicated server login (unique identifier)',
    example: 'pixel-elite-1.server.local',
  })
  @ApiParam({
    name: 'login',
    description: 'The player login to query turn history for',
    example: 'player1.ubisoft.com',
  })
  @ApiQuery({ name: 'limit', required: false, description: 'Max turns to return (1–200, default 50)', example: 50 })
  @ApiQuery({ name: 'offset', required: false, description: 'Turns to skip (default 0)', example: 0 })
  @ApiResponse({
    status: 200,
    description:
      'Paginated list of per-player turn entries. Fields: turn_number, map_uid, map_name, recorded_at, ' +
      'role (attacker|defender), stats { kills, deaths, hits, shots, misses, rocket_hits }, ' +
      'outcome, defense_success, clutch { is_clutch, clutch_player_login, alive_defenders_at_end, total_defenders }.',
  })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Get(':serverLogin/stats/combat/players/:login/turns')
  async getElitePlayerTurnHistory(
    @Param('serverLogin') serverLogin: string,
    @Param('login') playerLogin: string,
    @Query() query: PaginationQueryDto,
  ): Promise<PlayerEliteTurnHistoryResponse> {
    return this.eliteStatsService.getElitePlayerTurnHistory(
      serverLogin,
      playerLogin,
      query.limit ?? 50,
      query.offset ?? 0,
    );
  }
}
