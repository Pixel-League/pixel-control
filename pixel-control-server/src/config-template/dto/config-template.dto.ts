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
// AdminConfigDto — mirrors AdminStateDto from server-state/dto/server-state.dto.ts
// Kept as a separate class to avoid circular dependency issues.
// ---------------------------------------------------------------------------

export class AdminConfigDto {
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
// Create DTO
// ---------------------------------------------------------------------------

export class CreateConfigTemplateDto {
  @ApiProperty({ description: 'Template name (unique)', example: 'Elite Standard' })
  @IsString()
  @IsNotEmpty()
  name!: string;

  @ApiPropertyOptional({ description: 'Optional description', example: 'Default Elite tournament configuration' })
  @IsString()
  @IsOptional()
  description?: string;

  @ApiProperty({ type: AdminConfigDto, description: 'Full admin configuration' })
  @ValidateNested()
  @Type(() => AdminConfigDto)
  config!: AdminConfigDto;
}

// ---------------------------------------------------------------------------
// Update DTO
// ---------------------------------------------------------------------------

export class UpdateConfigTemplateDto {
  @ApiPropertyOptional({ description: 'Template name (unique)', example: 'Elite Standard v2' })
  @IsString()
  @IsOptional()
  name?: string;

  @ApiPropertyOptional({ description: 'Optional description' })
  @IsString()
  @IsOptional()
  description?: string;

  @ApiPropertyOptional({ type: AdminConfigDto, description: 'Full admin configuration' })
  @ValidateNested()
  @IsOptional()
  @Type(() => AdminConfigDto)
  config?: AdminConfigDto;
}

// ---------------------------------------------------------------------------
// Link DTO
// ---------------------------------------------------------------------------

export class LinkServerToTemplateDto {
  @ApiProperty({ description: 'Template ID to link', example: 'uuid-of-template' })
  @IsString()
  @IsNotEmpty()
  template_id!: string;
}

// ---------------------------------------------------------------------------
// Response interfaces
// ---------------------------------------------------------------------------

export interface ConfigTemplateResponse {
  id: string;
  name: string;
  description: string | null;
  config: Record<string, unknown>;
  server_count: number;
  created_at: string;
  updated_at: string;
}

export type ConfigTemplateListResponse = ConfigTemplateResponse[];
