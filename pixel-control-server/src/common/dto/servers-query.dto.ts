import { IsEnum, IsOptional } from 'class-validator';

export enum ServerStatusFilter {
  ALL = 'all',
  LINKED = 'linked',
  OFFLINE = 'offline',
}

export class ServersQueryDto {
  @IsOptional()
  @IsEnum(ServerStatusFilter)
  status?: ServerStatusFilter;
}
