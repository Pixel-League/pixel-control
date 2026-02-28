import { ApiPropertyOptional } from '@nestjs/swagger';
import { Type } from 'class-transformer';
import { IsInt, IsISO8601, IsOptional, Max, Min } from 'class-validator';

/**
 * Standard pagination query parameters.
 */
export class PaginationQueryDto {
  @ApiPropertyOptional({
    description: 'Maximum number of items to return (1–200)',
    minimum: 1,
    maximum: 200,
    default: 50,
    example: 50,
  })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  @Max(200)
  limit?: number = 50;

  @ApiPropertyOptional({
    description: 'Number of items to skip before returning results',
    minimum: 0,
    default: 0,
    example: 0,
  })
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(0)
  offset?: number = 0;
}

/**
 * Time-range filter query parameters.
 */
export class TimeRangeQueryDto {
  @ApiPropertyOptional({
    description: 'Filter events after this ISO8601 timestamp (inclusive)',
    example: '2026-02-28T09:00:00Z',
  })
  @IsOptional()
  @IsISO8601()
  since?: string;

  @ApiPropertyOptional({
    description: 'Filter events before this ISO8601 timestamp (inclusive)',
    example: '2026-02-28T10:00:00Z',
  })
  @IsOptional()
  @IsISO8601()
  until?: string;
}

/**
 * Combined pagination + time-range query parameters.
 */
export class PaginatedTimeRangeQueryDto extends PaginationQueryDto {
  @ApiPropertyOptional({
    description: 'Filter events after this ISO8601 timestamp (inclusive)',
    example: '2026-02-28T09:00:00Z',
  })
  @IsOptional()
  @IsISO8601()
  since?: string;

  @ApiPropertyOptional({
    description: 'Filter events before this ISO8601 timestamp (inclusive)',
    example: '2026-02-28T10:00:00Z',
  })
  @IsOptional()
  @IsISO8601()
  until?: string;
}
