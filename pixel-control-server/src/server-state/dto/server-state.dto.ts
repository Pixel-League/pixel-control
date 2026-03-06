import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { Type } from 'class-transformer';
import {
  IsArray,
  IsBoolean,
  IsNotEmpty,
  IsNumber,
  IsObject,
  IsOptional,
  IsString,
  ValidateNested,
} from 'class-validator';

// ---------------------------------------------------------------------------
// Nested DTO: admin runtime state
// ---------------------------------------------------------------------------

export class AdminStateDto {
  @ApiProperty({ description: 'Current best-of setting', example: 3 })
  @IsNumber()
  current_best_of!: number;

  @ApiProperty({ description: 'Team maps score snapshot', example: { team_a: 0, team_b: 0 } })
  @IsObject()
  team_maps_score!: Record<string, number>;

  @ApiProperty({ description: 'Team round score snapshot', example: { team_a: 0, team_b: 0 } })
  @IsObject()
  team_round_score!: Record<string, number>;

  @ApiProperty({ description: 'Whether team policy (force-team restrictions) is enabled', example: false })
  @IsBoolean()
  team_policy_enabled!: boolean;

  @ApiProperty({ description: 'Whether team switch lock is active', example: false })
  @IsBoolean()
  team_switch_lock!: boolean;

  @ApiProperty({ description: 'Team roster mapping: login -> team_a|team_b', example: {} })
  @IsObject()
  team_roster!: Record<string, string>;

  @ApiProperty({ description: 'Whether the server whitelist is enabled', example: false })
  @IsBoolean()
  whitelist_enabled!: boolean;

  @ApiProperty({ description: 'List of whitelisted player logins', example: [] })
  @IsArray()
  whitelist!: string[];

  @ApiProperty({ description: 'Current vote policy mode', example: 'default' })
  @IsString()
  @IsNotEmpty()
  vote_policy!: string;

  @ApiProperty({ description: 'Per-command vote ratios: command -> float 0-1', example: {} })
  @IsObject()
  vote_ratios!: Record<string, number>;
}

// ---------------------------------------------------------------------------
// Nested DTO: veto-draft session state
// ---------------------------------------------------------------------------

export class VetoDraftStateDto {
  @ApiPropertyOptional({ description: 'Active veto/draft session data, or null when idle', example: null })
  @IsOptional()
  session!: Record<string, unknown> | null;

  @ApiProperty({ description: 'Whether the matchmaking ready gate has been armed', example: false })
  @IsBoolean()
  matchmaking_ready_armed!: boolean;

  @ApiProperty({ description: 'Matchmaking votes map: actor_login -> map_uid', example: {} })
  @IsObject()
  votes!: Record<string, string>;
}

// ---------------------------------------------------------------------------
// Top-level snapshot DTO (POST /servers/:serverLogin/state body)
// ---------------------------------------------------------------------------

export class ServerStateSnapshotDto {
  @ApiProperty({ description: 'State schema version', example: '1.0' })
  @IsString()
  @IsNotEmpty()
  state_version!: string;

  @ApiProperty({ description: 'Unix timestamp when snapshot was captured', example: 1741276800 })
  @IsNumber()
  captured_at!: number;

  @ApiProperty({ type: AdminStateDto, description: 'Admin runtime state' })
  @ValidateNested()
  @Type(() => AdminStateDto)
  admin!: AdminStateDto;

  @ApiProperty({ type: VetoDraftStateDto, description: 'Veto/draft session state' })
  @ValidateNested()
  @Type(() => VetoDraftStateDto)
  veto_draft!: VetoDraftStateDto;
}

// ---------------------------------------------------------------------------
// Response shapes
// ---------------------------------------------------------------------------

export interface GetStateResponse {
  state: Record<string, unknown> | null;
  updated_at: string | null;
}

export interface SaveStateResponse {
  saved: boolean;
  updated_at: string;
}
