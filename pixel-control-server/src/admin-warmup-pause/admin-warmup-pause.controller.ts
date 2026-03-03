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

import { WarmupExtendDto } from '../admin-proxy/dto/admin-action.dto';
import { AdminWarmupPauseService } from './admin-warmup-pause.service';

@ApiTags('Admin - Warmup/Pause')
@Controller('servers')
export class AdminWarmupPauseController {
  constructor(private readonly adminWarmupPauseService: AdminWarmupPauseService) {}

  // ─── P3.7 Extend Warmup ──────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Extend the warmup duration',
    description: 'Extends the current warmup phase by the specified number of seconds.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiBody({ type: WarmupExtendDto })
  @ApiResponse({ status: 200, description: 'Warmup extended successfully.' })
  @ApiResponse({ status: 400, description: 'Invalid seconds value.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/warmup/extend')
  @HttpCode(200)
  extendWarmup(
    @Param('serverLogin') serverLogin: string,
    @Body() body: WarmupExtendDto,
  ) {
    return this.adminWarmupPauseService.extendWarmup(serverLogin, body.seconds);
  }

  // ─── P3.8 End Warmup ─────────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'End the warmup phase immediately',
    description: 'Instructs the server to end the warmup phase immediately and start the match.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Warmup ended successfully.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/warmup/end')
  @HttpCode(200)
  endWarmup(@Param('serverLogin') serverLogin: string) {
    return this.adminWarmupPauseService.endWarmup(serverLogin);
  }

  // ─── P3.9 Start Pause ────────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Pause the match',
    description: 'Instructs the server to pause the current match.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Match paused successfully.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/pause/start')
  @HttpCode(200)
  startPause(@Param('serverLogin') serverLogin: string) {
    return this.adminWarmupPauseService.startPause(serverLogin);
  }

  // ─── P3.10 End Pause ─────────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Resume the match from pause',
    description: 'Instructs the server to end the pause and resume the match.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Match resumed successfully.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/pause/end')
  @HttpCode(200)
  endPause(@Param('serverLogin') serverLogin: string) {
    return this.adminWarmupPauseService.endPause(serverLogin);
  }
}
