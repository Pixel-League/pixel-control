import {
  IsInt,
  IsNotEmpty,
  IsObject,
  IsOptional,
  IsString,
} from 'class-validator';

export class EventEnvelopeDto {
  @IsString()
  @IsNotEmpty()
  event_name!: string;

  @IsString()
  @IsNotEmpty()
  schema_version!: string;

  @IsString()
  @IsNotEmpty()
  event_id!: string;

  @IsString()
  @IsNotEmpty()
  event_category!: string;

  @IsString()
  @IsNotEmpty()
  source_callback!: string;

  @IsInt()
  source_sequence!: number;

  @IsInt()
  source_time!: number;

  @IsString()
  @IsNotEmpty()
  idempotency_key!: string;

  @IsObject()
  payload!: Record<string, unknown>;

  @IsOptional()
  @IsObject()
  metadata?: Record<string, unknown>;
}
