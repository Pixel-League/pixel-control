import {
  Body,
  Controller,
  Delete,
  Get,
  HttpCode,
  Param,
  Post,
  Put,
} from '@nestjs/common';
import {
  ApiBody,
  ApiOperation,
  ApiParam,
  ApiResponse,
  ApiTags,
} from '@nestjs/swagger';

import { TeamPolicyDto, TeamRosterAssignDto } from '../admin-proxy/dto/admin-action.dto';
import { AdminTeamsService } from './admin-teams.service';

@ApiTags('Admin - Teams')
@Controller('servers')
export class AdminTeamsController {
  constructor(private readonly adminTeamsService: AdminTeamsService) {}

  // ─── P4.9 Set Team Policy ─────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Set team enforcement policy',
    description:
      'Enables or disables team enforcement. When enabled, players are locked to their assigned roster teams. ' +
      'Optional switch_lock prevents players from manually switching teams.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiBody({ type: TeamPolicyDto })
  @ApiResponse({ status: 200, description: 'Team policy updated successfully.' })
  @ApiResponse({ status: 400, description: 'Invalid or missing enabled parameter.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Put(':serverLogin/teams/policy')
  @HttpCode(200)
  setPolicy(
    @Param('serverLogin') serverLogin: string,
    @Body() body: TeamPolicyDto,
  ) {
    return this.adminTeamsService.setPolicy(serverLogin, body);
  }

  // ─── P4.10 Get Team Policy ────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Get current team enforcement policy',
    description:
      'Returns the current team enforcement policy state: enabled flag and switch_lock setting.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Team policy retrieved successfully.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Get(':serverLogin/teams/policy')
  getPolicy(@Param('serverLogin') serverLogin: string) {
    return this.adminTeamsService.getPolicy(serverLogin);
  }

  // ─── P4.11 Assign Roster ──────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Assign a player to a team in the roster',
    description:
      'Adds or updates a player\'s team assignment in the server roster. ' +
      'Accepted team values: "team_a", "team_b", "a", "b", "0", "1", "red", "blue".',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiBody({ type: TeamRosterAssignDto })
  @ApiResponse({ status: 200, description: 'Player assigned to team roster successfully.' })
  @ApiResponse({ status: 400, description: 'Invalid or missing target_login or team.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/teams/roster')
  @HttpCode(200)
  assignRoster(
    @Param('serverLogin') serverLogin: string,
    @Body() body: TeamRosterAssignDto,
  ) {
    return this.adminTeamsService.assignRoster(serverLogin, body);
  }

  // ─── P4.12 Unassign Roster ────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Remove a player from the team roster',
    description:
      'Removes the specified player from the server team roster. ' +
      'Returns an error if the player is not in the roster.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiParam({ name: 'login', description: 'Player login to remove from roster', example: 'player.login.example' })
  @ApiResponse({ status: 200, description: 'Player removed from roster successfully.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Delete(':serverLogin/teams/roster/:login')
  @HttpCode(200)
  unassignRoster(
    @Param('serverLogin') serverLogin: string,
    @Param('login') playerLogin: string,
  ) {
    return this.adminTeamsService.unassignRoster(serverLogin, playerLogin);
  }

  // ─── P4.13 List Roster ────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Get the current team roster',
    description: 'Returns the full team roster: a map of player logins to their assigned teams.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Team roster retrieved successfully.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Get(':serverLogin/teams/roster')
  listRoster(@Param('serverLogin') serverLogin: string) {
    return this.adminTeamsService.listRoster(serverLogin);
  }
}
