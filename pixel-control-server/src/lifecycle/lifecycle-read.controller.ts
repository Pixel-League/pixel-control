import { Controller, Get, Param, Query } from '@nestjs/common';
import {
  ApiOperation,
  ApiParam,
  ApiQuery,
  ApiResponse,
  ApiTags,
} from '@nestjs/swagger';

import { LifecycleReadService } from './lifecycle-read.service';

@ApiTags('Lifecycle')
@Controller('servers')
export class LifecycleReadController {
  constructor(private readonly lifecycleService: LifecycleReadService) {}

  @ApiOperation({
    summary: 'Get current lifecycle state',
    description:
      'Returns the current lifecycle state of the server, reconstructed from the latest ' +
      'lifecycle events for each phase (match, map, round, warmup, pause). ' +
      'Derives the current_phase from the most recent lifecycle event variant. ' +
      'Warmup and pause active status is determined from the latest warmup/pause event\'s phase (start vs end).',
  })
  @ApiParam({
    name: 'serverLogin',
    description: 'The dedicated server login (unique identifier)',
    example: 'pixel-elite-1.server.local',
  })
  @ApiResponse({
    status: 200,
    description:
      'Lifecycle state returned. Fields: server_login, current_phase, ' +
      'match/map/round (each: state, variant, source_time, event_id), ' +
      'warmup/pause (each: active, last_variant, source_time), last_updated (ISO8601). ' +
      'Null fields if no lifecycle events have been received.',
  })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Get(':serverLogin/lifecycle')
  async getLifecycleState(@Param('serverLogin') serverLogin: string) {
    return this.lifecycleService.getLifecycleState(serverLogin);
  }

  @ApiOperation({
    summary: 'Get map rotation and veto state',
    description:
      'Returns the current map rotation state extracted from the latest lifecycle event ' +
      'containing map_rotation data (typically map.begin and map.end events). ' +
      'Includes: map pool, current map, next maps, played map order, series targets, ' +
      'and veto/draft state (mode, session status, actions, result). ' +
      'If no map rotation data exists, returns 200 with no_rotation_data: true.',
  })
  @ApiParam({
    name: 'serverLogin',
    description: 'The dedicated server login (unique identifier)',
    example: 'pixel-elite-1.server.local',
  })
  @ApiResponse({
    status: 200,
    description:
      'Map rotation returned. Fields: server_login, map_pool (array), map_pool_size, ' +
      'current_map, current_map_index, next_maps, played_map_order, played_map_count, ' +
      'series_targets, veto { mode, session_status, ready_armed, actions, result, lifecycle }, ' +
      'source_time (ISO8601), event_id. If no data: no_rotation_data: true.',
  })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Get(':serverLogin/lifecycle/map-rotation')
  async getMapRotation(@Param('serverLogin') serverLogin: string) {
    return this.lifecycleService.getMapRotation(serverLogin);
  }

  @ApiOperation({
    summary: 'Get latest aggregate stats',
    description:
      'Returns the latest aggregate stats snapshots from lifecycle end events (round.end, map.end). ' +
      'Each aggregate includes: scope (round/map), counter_scope, player_counters_delta, totals, ' +
      'team_counters_delta, team_summary, tracked_player_count, window (time range), ' +
      'source_coverage, and win_context (result_state, winning_side, winning_reason). ' +
      'Filter by scope=round or scope=map to return only one scope\'s latest aggregate.',
  })
  @ApiParam({
    name: 'serverLogin',
    description: 'The dedicated server login (unique identifier)',
    example: 'pixel-elite-1.server.local',
  })
  @ApiQuery({
    name: 'scope',
    required: false,
    description: 'Filter aggregates by scope: "round" or "map". Omit to return both.',
    enum: ['round', 'map'],
    example: 'round',
  })
  @ApiResponse({
    status: 200,
    description:
      'Aggregate stats returned. Fields: server_login, aggregates (array). ' +
      'Each aggregate: scope, counter_scope, player_counters_delta, totals, team_counters_delta, ' +
      'team_summary, tracked_player_count, window, source_coverage, win_context, source_time, event_id.',
  })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Get(':serverLogin/lifecycle/aggregate-stats')
  async getAggregateStats(
    @Param('serverLogin') serverLogin: string,
    @Query('scope') scope?: string,
  ) {
    return this.lifecycleService.getAggregateStats(serverLogin, scope);
  }
}
