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

import { MapAddDto, MapJumpDto, MapQueueDto } from '../admin-proxy/dto/admin-action.dto';
import { AdminMapsService } from './admin-maps.service';

@ApiTags('Admin - Maps')
@Controller('servers')
export class AdminMapsController {
  constructor(private readonly adminMapsService: AdminMapsService) {}

  // ─── P3.1 Skip Map ───────────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Skip to the next map',
    description: 'Instructs the server to immediately skip to the next map in the rotation.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Map skipped successfully.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/maps/skip')
  @HttpCode(200)
  skipMap(@Param('serverLogin') serverLogin: string) {
    return this.adminMapsService.skipMap(serverLogin);
  }

  // ─── P3.2 Restart Map ────────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Restart the current map',
    description: 'Instructs the server to restart the currently running map.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Map restarted successfully.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/maps/restart')
  @HttpCode(200)
  restartMap(@Param('serverLogin') serverLogin: string) {
    return this.adminMapsService.restartMap(serverLogin);
  }

  // ─── P3.3 Jump to Map ────────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Jump to a specific map by UID',
    description: 'Instructs the server to immediately jump to the map with the given UID.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiBody({ type: MapJumpDto })
  @ApiResponse({ status: 200, description: 'Map jumped successfully.' })
  @ApiResponse({ status: 400, description: 'Invalid or missing map_uid.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found or map UID not in pool.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/maps/jump')
  @HttpCode(200)
  jumpToMap(
    @Param('serverLogin') serverLogin: string,
    @Body() body: MapJumpDto,
  ) {
    return this.adminMapsService.jumpToMap(serverLogin, body.map_uid);
  }

  // ─── P3.4 Queue Map ──────────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Queue a map as the next map',
    description: 'Instructs the server to queue the specified map to play next after the current one.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiBody({ type: MapQueueDto })
  @ApiResponse({ status: 200, description: 'Map queued successfully.' })
  @ApiResponse({ status: 400, description: 'Invalid or missing map_uid.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found or map UID not in pool.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/maps/queue')
  @HttpCode(200)
  queueMap(
    @Param('serverLogin') serverLogin: string,
    @Body() body: MapQueueDto,
  ) {
    return this.adminMapsService.queueMap(serverLogin, body.map_uid);
  }

  // ─── P3.5 Add Map from ManiaExchange ─────────────────────────────────────────

  @ApiOperation({
    summary: 'Add a map from ManiaExchange',
    description: 'Downloads the map with the given ManiaExchange ID and adds it to the server map pool.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiBody({ type: MapAddDto })
  @ApiResponse({ status: 200, description: 'Map added successfully.' })
  @ApiResponse({ status: 400, description: 'Invalid or missing mx_id.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/maps')
  @HttpCode(200)
  addMap(
    @Param('serverLogin') serverLogin: string,
    @Body() body: MapAddDto,
  ) {
    return this.adminMapsService.addMap(serverLogin, body.mx_id);
  }

  // ─── P3.6 Remove Map ─────────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Remove a map from the server map pool',
    description: 'Removes the map with the given UID from the server\'s map pool.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiParam({ name: 'mapUid', description: 'UID of the map to remove', example: 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' })
  @ApiResponse({ status: 200, description: 'Map removed successfully.' })
  @ApiResponse({ status: 400, description: 'Invalid map UID.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found or map UID not in pool.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Delete(':serverLogin/maps/:mapUid')
  @HttpCode(200)
  removeMap(
    @Param('serverLogin') serverLogin: string,
    @Param('mapUid') mapUid: string,
  ) {
    return this.adminMapsService.removeMap(serverLogin, mapUid);
  }
}
