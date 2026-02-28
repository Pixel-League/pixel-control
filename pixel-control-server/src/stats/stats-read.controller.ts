import {
  Controller,
  Get,
  NotFoundException,
  Param,
  Query,
} from '@nestjs/common';
import {
  ApiOperation,
  ApiParam,
  ApiQuery,
  ApiResponse,
  ApiTags,
} from '@nestjs/swagger';

import { PaginatedTimeRangeQueryDto, TimeRangeQueryDto } from '../common/dto/query-params.dto';
import { StatsReadService } from './stats-read.service';

@ApiTags('Stats')
@Controller('servers')
export class StatsReadController {
  constructor(private readonly statsService: StatsReadService) {}

  @ApiOperation({
    summary: 'Get aggregated combat stats',
    description:
      'Returns aggregated combat statistics for the server. Counter values come from the latest ' +
      'combat event (plugin counters are cumulative session totals, not deltas). Includes total kills, ' +
      'deaths, hits, shots, accuracy, tracked player count, and event kind breakdown. ' +
      'Supports optional time-range filtering via since/until (ISO8601).',
  })
  @ApiParam({
    name: 'serverLogin',
    description: 'The dedicated server login (unique identifier)',
    example: 'pixel-elite-1.server.local',
  })
  @ApiQuery({ name: 'since', required: false, description: 'Filter events after this ISO8601 timestamp', example: '2026-02-28T09:00:00Z' })
  @ApiQuery({ name: 'until', required: false, description: 'Filter events before this ISO8601 timestamp', example: '2026-02-28T10:00:00Z' })
  @ApiResponse({
    status: 200,
    description:
      'Combat summary returned. Fields: server_login, combat_summary { total_events, total_kills, total_deaths, ' +
      'total_hits, total_shots, total_accuracy, tracked_player_count, event_kinds {} }, ' +
      'time_range { since, until, event_count }.',
  })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Get(':serverLogin/stats/combat')
  async getCombatStats(
    @Param('serverLogin') serverLogin: string,
    @Query() query: TimeRangeQueryDto,
  ) {
    return this.statsService.getCombatStats(serverLogin, query.since, query.until);
  }

  @ApiOperation({
    summary: 'Get per-player combat counters',
    description:
      'Returns per-player combat counters from the latest combat event for this server. ' +
      'The counters reflect cumulative runtime session totals (kills, deaths, hits, shots, misses, ' +
      'rockets, lasers, accuracy). Supports pagination and optional time-range filtering.',
  })
  @ApiParam({
    name: 'serverLogin',
    description: 'The dedicated server login (unique identifier)',
    example: 'pixel-elite-1.server.local',
  })
  @ApiQuery({ name: 'limit', required: false, description: 'Max items to return (1–200, default 50)', example: 50 })
  @ApiQuery({ name: 'offset', required: false, description: 'Items to skip (default 0)', example: 0 })
  @ApiQuery({ name: 'since', required: false, description: 'Filter events after this ISO8601 timestamp', example: '2026-02-28T09:00:00Z' })
  @ApiQuery({ name: 'until', required: false, description: 'Filter events before this ISO8601 timestamp', example: '2026-02-28T10:00:00Z' })
  @ApiResponse({
    status: 200,
    description:
      'Per-player counters returned. Fields: data (array of { login, kills, deaths, hits, shots, misses, rockets, lasers, accuracy }), ' +
      'pagination { total, limit, offset }.',
  })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Get(':serverLogin/stats/combat/players')
  async getCombatPlayers(
    @Param('serverLogin') serverLogin: string,
    @Query() query: PaginatedTimeRangeQueryDto,
  ) {
    return this.statsService.getCombatPlayersCounters(
      serverLogin,
      query.limit ?? 50,
      query.offset ?? 0,
      query.since,
      query.until,
    );
  }

  @ApiOperation({
    summary: 'Get single player combat counters',
    description:
      'Returns combat counters for a specific player login, from the most recent combat event ' +
      'containing that player\'s data. Also includes the total count of combat events for context ' +
      'and the timestamp of the last update.',
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
      'Player combat counters returned. Fields: login, counters { kills, deaths, hits, shots, misses, rockets, lasers, accuracy }, ' +
      'recent_events_count, last_updated (ISO8601).',
  })
  @ApiResponse({ status: 404, description: 'Server not found or no combat data for this player.' })
  @Get(':serverLogin/stats/combat/players/:login')
  async getPlayerCombatCounters(
    @Param('serverLogin') serverLogin: string,
    @Param('login') login: string,
  ) {
    try {
      return await this.statsService.getPlayerCombatCounters(serverLogin, login);
    } catch (err) {
      if (err instanceof NotFoundException) {
        throw err;
      }
      throw new NotFoundException(`No combat data found for player '${login}' on server '${serverLogin}'`);
    }
  }

  @ApiOperation({
    summary: 'Get latest scores snapshot',
    description:
      'Returns the latest scores snapshot from the most recent SM_SCORES callback event. ' +
      'Includes scores_section (EndRound/EndMap/EndMatch), scores_snapshot (teams, players, ranks, points), ' +
      'and scores_result (result_state, winning_side, winning_reason). ' +
      'If no scores event has been received, returns 200 with no_scores_available: true.',
  })
  @ApiParam({
    name: 'serverLogin',
    description: 'The dedicated server login (unique identifier)',
    example: 'pixel-elite-1.server.local',
  })
  @ApiResponse({
    status: 200,
    description:
      'Scores snapshot returned. Fields: server_login, scores_section, scores_snapshot, scores_result, ' +
      'source_time (ISO8601), event_id. If no scores: no_scores_available: true with null fields.',
  })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Get(':serverLogin/stats/scores')
  async getLatestScores(@Param('serverLogin') serverLogin: string) {
    return this.statsService.getLatestScores(serverLogin);
  }
}
