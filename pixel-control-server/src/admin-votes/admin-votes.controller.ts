import {
  Body,
  Controller,
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

import {
  VoteCustomStartDto,
  VotePolicySetDto,
  VoteSetRatioDto,
} from '../admin-proxy/dto/admin-action.dto';
import { AdminVotesService } from './admin-votes.service';

@ApiTags('Admin - Votes')
@Controller('servers')
export class AdminVotesController {
  constructor(private readonly adminVotesService: AdminVotesService) {}

  // ─── P5.10 Cancel Vote ────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Cancel the current vote',
    description: 'Cancels any currently running vote on the server.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Vote cancelled successfully.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/votes/cancel')
  @HttpCode(200)
  cancelVote(@Param('serverLogin') serverLogin: string) {
    return this.adminVotesService.cancelVote(serverLogin);
  }

  // ─── P5.11 Set Vote Ratio ─────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Set required vote ratio for a command',
    description: 'Sets the minimum ratio of votes (0.0–1.0) required to pass the specified vote command.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiBody({ type: VoteSetRatioDto })
  @ApiResponse({ status: 200, description: 'Vote ratio updated successfully.' })
  @ApiResponse({ status: 400, description: 'Invalid or missing command/ratio.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Put(':serverLogin/votes/ratio')
  @HttpCode(200)
  setVoteRatio(
    @Param('serverLogin') serverLogin: string,
    @Body() body: VoteSetRatioDto,
  ) {
    return this.adminVotesService.setVoteRatio(serverLogin, body.command, body.ratio);
  }

  // ─── P5.12 Start Custom Vote ──────────────────────────────────────────────

  @ApiOperation({
    summary: 'Start a custom vote',
    description: 'Starts the custom vote at the specified index defined in ManiaControl\'s vote configuration.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiBody({ type: VoteCustomStartDto })
  @ApiResponse({ status: 200, description: 'Custom vote started successfully.' })
  @ApiResponse({ status: 400, description: 'Invalid or missing vote_index.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/votes/custom')
  @HttpCode(200)
  startCustomVote(
    @Param('serverLogin') serverLogin: string,
    @Body() body: VoteCustomStartDto,
  ) {
    return this.adminVotesService.startCustomVote(serverLogin, body.vote_index);
  }

  // ─── P5.13 Get Vote Policy ────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Get current vote policy',
    description: 'Returns the current vote policy mode configured on the server.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Vote policy returned.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Get(':serverLogin/votes/policy')
  getVotePolicy(@Param('serverLogin') serverLogin: string) {
    return this.adminVotesService.getVotePolicy(serverLogin);
  }

  // ─── P5.14 Set Vote Policy ────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Set vote policy',
    description: 'Sets the vote policy mode (e.g. "strict", "lenient", "off") for the server.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiBody({ type: VotePolicySetDto })
  @ApiResponse({ status: 200, description: 'Vote policy updated successfully.' })
  @ApiResponse({ status: 400, description: 'Invalid or missing mode.' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Put(':serverLogin/votes/policy')
  @HttpCode(200)
  setVotePolicy(
    @Param('serverLogin') serverLogin: string,
    @Body() body: VotePolicySetDto,
  ) {
    return this.adminVotesService.setVotePolicy(serverLogin, body.mode);
  }
}
