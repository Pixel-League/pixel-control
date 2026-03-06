import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsBoolean, IsInt, IsNotEmpty, IsOptional, IsString, Min } from 'class-validator';

// ---------------------------------------------------------------------------
// Response interfaces (TypeScript types only — not DTO classes)
// ---------------------------------------------------------------------------

export interface VetoDraftCommandResponse {
  success: boolean;
  code: string;
  message: string;
  details?: Record<string, unknown>;
}

export interface VetoDraftStatusResponse {
  success: boolean;
  code: string;
  message: string;
  status?: {
    active: boolean;
    mode: string | null;
    session?: Record<string, unknown>;
  };
  communication?: {
    methods: string[];
  };
  series_targets?: Record<string, unknown>;
  details?: Record<string, unknown>;
}

// ---------------------------------------------------------------------------
// Request body DTOs
// ---------------------------------------------------------------------------

export class VetoDraftStartDto {
  @ApiProperty({
    description: 'Veto/draft mode: "matchmaking_vote" or "tournament_draft"',
    example: 'tournament_draft',
  })
  @IsString()
  @IsNotEmpty()
  mode!: string;

  @ApiPropertyOptional({
    description: 'Duration in seconds for the matchmaking vote phase',
    example: 60,
    minimum: 1,
  })
  @IsOptional()
  @IsInt()
  @Min(1)
  duration_seconds?: number;

  @ApiPropertyOptional({
    description: 'Login of captain for Team A (required in tournament_draft mode)',
    example: 'player.login.team.a',
  })
  @IsOptional()
  @IsString()
  @IsNotEmpty()
  captain_a?: string;

  @ApiPropertyOptional({
    description: 'Login of captain for Team B (required in tournament_draft mode)',
    example: 'player.login.team.b',
  })
  @IsOptional()
  @IsString()
  @IsNotEmpty()
  captain_b?: string;

  @ApiPropertyOptional({
    description: 'Best-of target for the series (1, 3, 5)',
    example: 3,
    minimum: 1,
  })
  @IsOptional()
  @IsInt()
  @Min(1)
  best_of?: number;

  @ApiPropertyOptional({
    description: 'Which team starts the veto: "team_a", "team_b", or "random"',
    example: 'random',
  })
  @IsOptional()
  @IsString()
  starter?: string;

  @ApiPropertyOptional({
    description: 'Timeout in seconds for each action in tournament_draft mode',
    example: 30,
    minimum: 1,
  })
  @IsOptional()
  @IsInt()
  @Min(1)
  action_timeout_seconds?: number;

  @ApiPropertyOptional({
    description: 'If true, launch the first map immediately when veto completes',
    example: false,
  })
  @IsOptional()
  @IsBoolean()
  launch_immediately?: boolean;
}

export class VetoDraftActionDto {
  @ApiProperty({
    description: 'Login of the player performing the action (captain or voter)',
    example: 'player.login.team.a',
  })
  @IsString()
  @IsNotEmpty()
  actor_login!: string;

  @ApiPropertyOptional({
    description: 'Operation type: "ban" or "pick" (tournament_draft only)',
    example: 'ban',
  })
  @IsOptional()
  @IsString()
  operation?: string;

  @ApiPropertyOptional({
    description: 'UID of the map to ban/pick/vote for',
    example: 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
  })
  @IsOptional()
  @IsString()
  map?: string;

  @ApiPropertyOptional({
    description: 'Selection alias (e.g. "random")',
    example: 'random',
  })
  @IsOptional()
  @IsString()
  selection?: string;

  @ApiPropertyOptional({
    description: 'If true, overrides an existing vote (matchmaking mode)',
    example: false,
  })
  @IsOptional()
  @IsBoolean()
  allow_override?: boolean;

  @ApiPropertyOptional({
    description: 'If true, forces the action even if not the actor\'s turn',
    example: false,
  })
  @IsOptional()
  @IsBoolean()
  force?: boolean;
}

export class VetoDraftCancelDto {
  @ApiPropertyOptional({
    description: 'Reason for cancelling the veto session',
    example: 'Admin cancelled the session',
  })
  @IsOptional()
  @IsString()
  reason?: string;
}
