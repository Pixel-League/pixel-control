import { Injectable, NotFoundException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { Server } from '@prisma/client';

import { isServerOnline } from '../utils/online-status.util';
import { PrismaService } from '../../prisma/prisma.service';

export interface ResolvedServer {
  server: Server;
  online: boolean;
}

/**
 * Shared utility service for resolving a serverLogin to a Server record.
 * Throws NotFoundException if the server is not found.
 * Also computes the online status based on the lastHeartbeat.
 */
@Injectable()
export class ServerResolverService {
  private readonly onlineThresholdSeconds: number;

  constructor(
    private readonly prisma: PrismaService,
    private readonly config: ConfigService,
  ) {
    this.onlineThresholdSeconds =
      this.config.get<number>('ONLINE_THRESHOLD_SECONDS') ?? 360;
  }

  async resolve(serverLogin: string): Promise<ResolvedServer> {
    const server = await this.prisma.server.findUnique({
      where: { serverLogin },
    });

    if (!server) {
      throw new NotFoundException(`Server '${serverLogin}' not found`);
    }

    const online = server.lastHeartbeat
      ? isServerOnline(server.lastHeartbeat, this.onlineThresholdSeconds)
      : false;

    return { server, online };
  }
}
