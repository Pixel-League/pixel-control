import { IsBoolean, IsOptional } from 'class-validator';

export class LinkTokenDto {
  @IsOptional()
  @IsBoolean()
  rotate?: boolean;
}
