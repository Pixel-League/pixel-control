import { Controller, Get, Param } from '@nestjs/common';
import {
  ApiOperation,
  ApiParam,
  ApiResponse,
  ApiTags,
} from '@nestjs/swagger';

import { MapsReadService } from './maps-read.service';

@ApiTags('Maps')
@Controller('servers')
export class MapsReadController {
  constructor(private readonly mapsService: MapsReadService) {}

  @ApiOperation({
    summary: 'Get server map pool',
    description:
      'Returns the map pool for a server, extracted from the latest lifecycle event containing ' +
      'map_rotation data (map.begin or map.end events). Includes each map\'s uid, name, and file path. ' +
      'Also returns the current active map and its index in the pool. ' +
      'If no map rotation data exists, returns an empty list.',
  })
  @ApiParam({
    name: 'serverLogin',
    description: 'The dedicated server login (unique identifier)',
    example: 'pixel-elite-1.server.local',
  })
  @ApiResponse({
    status: 200,
    description:
      'Map pool returned. Fields: server_login, maps (array of { uid, name, file }), ' +
      'map_count, current_map, current_map_index, last_updated (ISO8601 or null).',
  })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @Get(':serverLogin/maps')
  async getMaps(@Param('serverLogin') serverLogin: string) {
    return this.mapsService.getMaps(serverLogin);
  }
}
