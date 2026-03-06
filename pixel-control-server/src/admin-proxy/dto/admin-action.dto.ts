import { ApiProperty } from '@nestjs/swagger';
import { IsBoolean, IsInt, IsNotEmpty, IsOptional, IsString, Min } from 'class-validator';

// ---------------------------------------------------------------------------
// Response interface (not a DTO class — used as TypeScript type only)
// ---------------------------------------------------------------------------

export interface AdminActionResponse {
  action_name: string;
  success: boolean;
  code: string;
  message: string;
  details?: Record<string, unknown>;
}

// ---------------------------------------------------------------------------
// Request body DTOs
// ---------------------------------------------------------------------------

export class MapJumpDto {
  @ApiProperty({ description: 'UID of the map to jump to', example: 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' })
  @IsString()
  @IsNotEmpty()
  map_uid!: string;
}

export class MapQueueDto {
  @ApiProperty({ description: 'UID of the map to queue as next', example: 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' })
  @IsString()
  @IsNotEmpty()
  map_uid!: string;
}

export class MapAddDto {
  @ApiProperty({ description: 'ManiaExchange map ID to add to the server map pool', example: '12345' })
  @IsString()
  @IsNotEmpty()
  mx_id!: string;
}

export class WarmupExtendDto {
  @ApiProperty({ description: 'Number of seconds to extend the warmup', example: 30, minimum: 1 })
  @IsInt()
  @Min(1)
  seconds!: number;
}

export class MatchBestOfDto {
  @ApiProperty({ description: 'Best-of target (must be a positive odd integer, e.g. 1, 3, 5)', example: 3, minimum: 1 })
  @IsInt()
  @Min(1)
  best_of!: number;
}

export class MatchMapsScoreDto {
  @ApiProperty({ description: 'Team identifier: "team_a" or "team_b"', example: 'team_a' })
  @IsString()
  @IsNotEmpty()
  target_team!: string;

  @ApiProperty({ description: 'New maps score value for the team', example: 1, minimum: 0 })
  @IsInt()
  @Min(0)
  maps_score!: number;
}

export class MatchRoundScoreDto {
  @ApiProperty({ description: 'Team identifier: "team_a" or "team_b"', example: 'team_a' })
  @IsString()
  @IsNotEmpty()
  target_team!: string;

  @ApiProperty({ description: 'New round score value for the team', example: 100, minimum: 0 })
  @IsInt()
  @Min(0)
  score!: number;
}

// ---------------------------------------------------------------------------
// P4 Player management DTOs
// ---------------------------------------------------------------------------

export class ForceTeamDto {
  @ApiProperty({ description: 'Team identifier: "team_a", "team_b", "0", "1", "red", "blue", "a", or "b"', example: 'team_a' })
  @IsString()
  @IsNotEmpty()
  team!: string;
}

// ---------------------------------------------------------------------------
// P4 Team control DTOs
// ---------------------------------------------------------------------------

export class TeamPolicyDto {
  @ApiProperty({ description: 'Whether team enforcement policy is enabled', example: true })
  @IsBoolean()
  enabled!: boolean;

  @ApiProperty({ description: 'Whether team switching is locked for players', example: false, required: false })
  @IsOptional()
  @IsBoolean()
  switch_lock?: boolean;
}

export class TeamRosterAssignDto {
  @ApiProperty({ description: 'Login of the player to assign to a team', example: 'player.login.example' })
  @IsString()
  @IsNotEmpty()
  target_login!: string;

  @ApiProperty({ description: 'Team to assign: "team_a", "team_b", "a", "b", "0", "1", "red", or "blue"', example: 'team_a' })
  @IsString()
  @IsNotEmpty()
  team!: string;
}
