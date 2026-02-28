import { Controller, Get, Param, Query } from '@nestjs/common';
import {
  ApiOperation,
  ApiParam,
  ApiQuery,
  ApiResponse,
  ApiTags,
} from '@nestjs/swagger';
import { Type } from 'class-transformer';
import { IsInt, IsOptional, Max, Min } from 'class-validator';

import { ModeReadService } from './mode-read.service';

class ModeQueryDto {
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  @Max(50)
  limit?: number = 10;
}

@ApiTags('Mode')
@Controller('servers')
export class ModeReadController {
  constructor(private readonly modeService: ModeReadService) {}

  @ApiOperation({
    summary: 'Get current game mode and recent mode events',
    description:
      'Returns the current game mode from the Server record (set by connectivity events), ' +
      'plus a list of the most recent mode events for this server. Mode events correspond to ' +
      'game-mode-specific callbacks (e.g. SM_ELITE_STARTTURN, SM_JOUST_NEWTURN, etc.). ' +
      'Use the limit parameter to control how many recent events are returned (1–50, default 10).',
  })
  @ApiParam({
    name: 'serverLogin',
    description: 'The dedicated server login (unique identifier)',
    example: 'pixel-elite-1.server.local',
  })
  @ApiQuery({
    name: 'limit',
    required: false,
    description: 'Number of recent mode events to return (1–50, default 10)',
    example: 10,
  })
  @ApiResponse({
    status: 200,
    description:
      'Mode data returned. Fields: server_login, game_mode, title_id, ' +
      'recent_mode_events (array of { event_name, event_id, source_callback, source_time, raw_callback_summary }), ' +
      'total_mode_events, last_updated (ISO8601 or null).',
  })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Get(':serverLogin/mode')
  async getModeData(
    @Param('serverLogin') serverLogin: string,
    @Query() query: ModeQueryDto,
  ) {
    return this.modeService.getModeData(serverLogin, query.limit ?? 10);
  }
}
