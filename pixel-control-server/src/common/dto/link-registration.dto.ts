import { IsOptional, IsString } from 'class-validator';

export class LinkRegistrationDto {
  @IsOptional()
  @IsString()
  server_name?: string;

  @IsOptional()
  @IsString()
  game_mode?: string;

  @IsOptional()
  @IsString()
  title_id?: string;
}
