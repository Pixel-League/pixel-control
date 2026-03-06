import {
  Body,
  Controller,
  Get,
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

import {
  VetoDraftActionDto,
  VetoDraftCancelDto,
  VetoDraftStartDto,
} from '../veto-draft-proxy/dto/veto-draft.dto';
import { VetoDraftService } from './veto-draft.service';

@ApiTags('Veto/Draft')
@Controller('servers')
export class VetoDraftController {
  constructor(private readonly vetoDraftService: VetoDraftService) {}

  // ─── P4.1 Get Veto Status ─────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Get veto/draft session status',
    description:
      'Returns the current state of the veto/draft session: idle, running, completed, or cancelled. ' +
      'Includes session details, map pool state, votes, and communication method names.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Veto status returned successfully.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Get(':serverLogin/veto/status')
  getStatus(@Param('serverLogin') serverLogin: string) {
    return this.vetoDraftService.getStatus(serverLogin);
  }

  // ─── P4.2 Arm Ready ───────────────────────────────────────────────────────

  @ApiOperation({
    summary: 'Arm the matchmaking ready gate',
    description:
      'Arms the matchmaking ready gate so a subsequent Start (matchmaking_vote mode) can proceed. ' +
      'Idempotent: calling twice returns "matchmaking_ready_already_armed".',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiResponse({ status: 200, description: 'Ready gate armed successfully.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/veto/ready')
  @HttpCode(200)
  armReady(@Param('serverLogin') serverLogin: string) {
    return this.vetoDraftService.armReady(serverLogin);
  }

  // ─── P4.3 Start Veto Session ──────────────────────────────────────────────

  @ApiOperation({
    summary: 'Start a veto/draft session',
    description:
      'Starts a new veto or matchmaking-vote session. ' +
      'For "tournament_draft" mode, captain_a and captain_b are required. ' +
      'For "matchmaking_vote" mode, the ready gate must be armed first.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiBody({ type: VetoDraftStartDto })
  @ApiResponse({ status: 200, description: 'Veto session started successfully.' })
  @ApiResponse({ status: 400, description: 'Invalid parameters (missing mode, captain conflict, etc.).' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/veto/start')
  @HttpCode(200)
  startSession(
    @Param('serverLogin') serverLogin: string,
    @Body() body: VetoDraftStartDto,
  ) {
    return this.vetoDraftService.startSession(serverLogin, body);
  }

  // ─── P4.4 Submit Veto Action ──────────────────────────────────────────────

  @ApiOperation({
    summary: 'Submit a veto/draft action (ban, pick, or vote)',
    description:
      'Submits a ban, pick, or vote action for the given actor. ' +
      'For tournament_draft: actor must be the current team captain. ' +
      'For matchmaking_vote: any player can vote.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiBody({ type: VetoDraftActionDto })
  @ApiResponse({ status: 200, description: 'Action submitted successfully.' })
  @ApiResponse({ status: 400, description: 'Invalid parameters (missing actor_login, session not running, etc.).' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/veto/action')
  @HttpCode(200)
  submitAction(
    @Param('serverLogin') serverLogin: string,
    @Body() body: VetoDraftActionDto,
  ) {
    return this.vetoDraftService.submitAction(serverLogin, body);
  }

  // ─── P4.5 Cancel Veto Session ─────────────────────────────────────────────

  @ApiOperation({
    summary: 'Cancel the active veto/draft session',
    description:
      'Cancels the currently running veto or matchmaking-vote session. ' +
      'Returns "session_not_running" if no session is active.',
  })
  @ApiParam({ name: 'serverLogin', description: 'Server login (unique identifier)', example: 'pixel-elite-1.server.local' })
  @ApiBody({ type: VetoDraftCancelDto, required: false })
  @ApiResponse({ status: 200, description: 'Veto session cancelled (or was already idle).' })
  @ApiResponse({ status: 403, description: 'Link auth rejected by plugin.' })
  @ApiResponse({ status: 404, description: 'Server not found.' })
  @ApiResponse({ status: 502, description: 'ManiaControl socket unavailable.' })
  @Post(':serverLogin/veto/cancel')
  @HttpCode(200)
  cancelSession(
    @Param('serverLogin') serverLogin: string,
    @Body() body?: VetoDraftCancelDto,
  ) {
    return this.vetoDraftService.cancelSession(serverLogin, body);
  }
}
