import { Injectable, NotFoundException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { randomUUID } from 'crypto';

import { LinkRegistrationDto } from '../common/dto/link-registration.dto';
import { LinkTokenDto } from '../common/dto/link-token.dto';
import { isServerOnline } from '../common/utils/online-status.util';
import { PrismaService } from '../prisma/prisma.service';

export interface RegisterServerResult {
  server_login: string;
  registered: boolean;
  link_token?: string;
}

export interface GenerateTokenResult {
  server_login: string;
  link_token: string;
  rotated: boolean;
}

export interface AuthStateResult {
  server_login: string;
  linked: boolean;
  last_heartbeat: string | null;
  plugin_version: string | null;
  online: boolean;
}

export interface AccessResult {
  server_login: string;
  access_granted: boolean;
  linked: boolean;
  online: boolean;
}

export interface DeleteServerResult {
  server_login: string;
  deleted: boolean;
}

export interface ServerListItem {
  server_login: string;
  server_name: string | null;
  linked: boolean;
  online: boolean;
  last_heartbeat: string | null;
  plugin_version: string | null;
  game_mode: string | null;
  title_id: string | null;
}

@Injectable()
export class LinkService {
  private readonly onlineThresholdSeconds: number;

  constructor(
    private readonly prisma: PrismaService,
    private readonly config: ConfigService,
  ) {
    this.onlineThresholdSeconds =
      this.config.get<number>('ONLINE_THRESHOLD_SECONDS') ?? 360;
  }

  async registerServer(
    serverLogin: string,
    dto: LinkRegistrationDto,
  ): Promise<RegisterServerResult> {
    const existing = await this.prisma.server.findUnique({
      where: { serverLogin },
    });

    if (!existing) {
      const linkToken = randomUUID();
      await this.prisma.server.create({
        data: {
          serverLogin,
          serverName: dto.server_name ?? null,
          gameMode: dto.game_mode ?? null,
          titleId: dto.title_id ?? null,
          linkToken,
          linked: true,
        },
      });

      return { server_login: serverLogin, registered: true, link_token: linkToken };
    }

    const updateData: {
      serverName?: string;
      gameMode?: string;
      titleId?: string;
    } = {};

    if (dto.server_name !== undefined) {
      updateData.serverName = dto.server_name;
    }
    if (dto.game_mode !== undefined) {
      updateData.gameMode = dto.game_mode;
    }
    if (dto.title_id !== undefined) {
      updateData.titleId = dto.title_id;
    }

    if (Object.keys(updateData).length > 0) {
      await this.prisma.server.update({
        where: { serverLogin },
        data: updateData,
      });
    }

    return { server_login: serverLogin, registered: true };
  }

  async generateToken(
    serverLogin: string,
    dto: LinkTokenDto,
  ): Promise<GenerateTokenResult> {
    const server = await this.prisma.server.findUnique({
      where: { serverLogin },
    });

    if (!server) {
      throw new NotFoundException(`Server '${serverLogin}' not found`);
    }

    if (!dto.rotate && server.linkToken) {
      return {
        server_login: serverLogin,
        link_token: server.linkToken,
        rotated: false,
      };
    }

    const newToken = randomUUID();
    await this.prisma.server.update({
      where: { serverLogin },
      data: { linkToken: newToken, linked: true },
    });

    return { server_login: serverLogin, link_token: newToken, rotated: true };
  }

  async getAuthState(serverLogin: string): Promise<AuthStateResult> {
    const server = await this.prisma.server.findUnique({
      where: { serverLogin },
    });

    if (!server) {
      throw new NotFoundException(`Server '${serverLogin}' not found`);
    }

    const online = isServerOnline(server.lastHeartbeat, this.onlineThresholdSeconds);

    return {
      server_login: serverLogin,
      linked: server.linked,
      last_heartbeat: server.lastHeartbeat?.toISOString() ?? null,
      plugin_version: server.pluginVersion,
      online,
    };
  }

  async checkAccess(serverLogin: string): Promise<AccessResult> {
    const server = await this.prisma.server.findUnique({
      where: { serverLogin },
    });

    if (!server) {
      throw new NotFoundException(`Server '${serverLogin}' not found`);
    }

    const online = isServerOnline(server.lastHeartbeat, this.onlineThresholdSeconds);

    return {
      server_login: serverLogin,
      access_granted: server.linked,
      linked: server.linked,
      online,
    };
  }

  async deleteServer(serverLogin: string): Promise<DeleteServerResult> {
    const server = await this.prisma.server.findUnique({
      where: { serverLogin },
    });

    if (!server) {
      throw new NotFoundException(`Server '${serverLogin}' not found`);
    }

    await this.prisma.connectivityEvent.deleteMany({
      where: { serverId: server.id },
    });

    await this.prisma.server.delete({
      where: { serverLogin },
    });

    return { server_login: serverLogin, deleted: true };
  }

  async listServers(status?: string): Promise<ServerListItem[]> {
    const servers = await this.prisma.server.findMany({
      orderBy: { createdAt: 'asc' },
    });

    const result: ServerListItem[] = servers.map((server) => ({
      server_login: server.serverLogin,
      server_name: server.serverName,
      linked: server.linked,
      online: isServerOnline(server.lastHeartbeat, this.onlineThresholdSeconds),
      last_heartbeat: server.lastHeartbeat?.toISOString() ?? null,
      plugin_version: server.pluginVersion,
      game_mode: server.gameMode,
      title_id: server.titleId,
    }));

    if (status === 'linked') {
      return result.filter((s) => s.linked);
    }

    if (status === 'offline') {
      return result.filter((s) => !s.online);
    }

    return result;
  }
}
