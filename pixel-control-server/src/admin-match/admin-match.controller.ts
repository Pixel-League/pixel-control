import {
  Body,
  Controller,
  Get,
  HttpCode,
  Param,
  Put,
} from '@nestjs/common';
import {
  ApiBody,
  ApiOperation,
  ApiParam,
  ApiResponse,
  ApiTags,
} from '@nestjs/swagger';

import {
  MatchBestOfDto,
  MatchMapsScoreDto,
  MatchRoundScoreDto,
} from '../admin-proxy/dto/admin-action.dto';
import { AdminMatchService } from './admin-match.service';

@ApiTags('Admin - Match')
@Controller('servers')
export class AdminMatchController {
  constructor(private readonly adminMatchService: AdminMatchService) {}

  // ─── P3.11 Set Best-of ───────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Set the best-of target',
    description: 'Sets the best-of match target (e.g. 3 for best-of-3). Must be a positive odd integer.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiBody({ type: MatchBestOfDto })
  @ApiResponse({ status: 200, description: 'Best-of target updated successfully.' })
  @ApiResponse({ status: 400, description: 'Invalid best_of value.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Put(':serverLogin/match/best-of')
  @HttpCode(200)
  setBestOf(
    @Param('serverLogin') serverLogin: string,
    @Body() body: MatchBestOfDto,
  ) {
    return this.adminMatchService.setBestOf(serverLogin, body.best_of);
  }

  // ─── P3.12 Get Best-of ───────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Get the current best-of target',
    description:
      'Retrieves the current best-of configuration from the live ManiaControl socket. ' +
      'Returns the details object containing the best_of value. Returns 503 if the socket is unavailable.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Current best-of returned in details.best_of.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket error.' })
  @ApiResponse({ status: 503, description: 'ManiaControl socket unavailable.' })
  @Get(':serverLogin/match/best-of')
  getBestOf(@Param('serverLogin') serverLogin: string) {
    return this.adminMatchService.getBestOf(serverLogin);
  }

  // ─── P3.13 Set Maps Score ────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Set the maps score for a team',
    description: 'Sets the maps score (wins) for the specified team.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiBody({ type: MatchMapsScoreDto })
  @ApiResponse({ status: 200, description: 'Maps score updated successfully.' })
  @ApiResponse({ status: 400, description: 'Invalid target_team or maps_score.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Put(':serverLogin/match/maps-score')
  @HttpCode(200)
  setMapsScore(
    @Param('serverLogin') serverLogin: string,
    @Body() body: MatchMapsScoreDto,
  ) {
    return this.adminMatchService.setMapsScore(serverLogin, body.target_team, body.maps_score);
  }

  // ─── P3.14 Get Maps Score ────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Get the current maps score',
    description:
      'Retrieves the current maps score state from the live ManiaControl socket. ' +
      'Returns details containing team maps scores. Returns 503 if the socket is unavailable.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Current maps score returned in details.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket error.' })
  @ApiResponse({ status: 503, description: 'ManiaControl socket unavailable.' })
  @Get(':serverLogin/match/maps-score')
  getMapsScore(@Param('serverLogin') serverLogin: string) {
    return this.adminMatchService.getMapsScore(serverLogin);
  }

  // ─── P3.15 Set Round Score ───────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Set the round score for a team',
    description: 'Sets the current round score for the specified team.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiBody({ type: MatchRoundScoreDto })
  @ApiResponse({ status: 200, description: 'Round score updated successfully.' })
  @ApiResponse({ status: 400, description: 'Invalid target_team or score.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Put(':serverLogin/match/round-score')
  @HttpCode(200)
  setRoundScore(
    @Param('serverLogin') serverLogin: string,
    @Body() body: MatchRoundScoreDto,
  ) {
    return this.adminMatchService.setRoundScore(serverLogin, body.target_team, body.score);
  }

  // ─── P3.16 Get Round Score ───────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Get the current round score',
    description:
      'Retrieves the current round score state from the live ManiaControl socket. ' +
      'Returns details containing team round scores. Returns 503 if the socket is unavailable.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Current round score returned in details.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket error.' })
  @ApiResponse({ status: 503, description: 'ManiaControl socket unavailable.' })
  @Get(':serverLogin/match/round-score')
  getRoundScore(@Param('serverLogin') serverLogin: string) {
    return this.adminMatchService.getRoundScore(serverLogin);
  }
}
