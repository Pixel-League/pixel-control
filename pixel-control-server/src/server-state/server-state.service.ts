import { ForbiddenException, Injectable } from '@nestjs/common';
import { Prisma } from '@prisma/client';

import { PrismaService } from '../prisma/prisma.service';
import { ServerResolverService } from '../common/services/server-resolver.service';
import { ServerStateSnapshotDto, GetStateResponse, SaveStateResponse } from './dto/server-state.dto';

/**
 * Service for persisting and retrieving plugin state snapshots per server.
 *
 * Each server has at most one state row (one-to-one via serverId @unique).
 * State is stored as a JSON blob — no schema normalization.
 *
 * Auth validation: compares the provided bearer token against the server's
 * stored linkToken. Throws ForbiddenException if they do not match.
 */
@Injectable()
export class ServerStateService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly serverResolver: ServerResolverService,
  ) {}

  /**
   * Returns the persisted state snapshot for the given server.
   * Returns null state if no snapshot has been saved yet.
   * Throws NotFoundException if the server is not registered.
   */
  async getState(serverLogin: string): Promise<GetStateResponse> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    const row = await this.prisma.serverState.findUnique({
      where: { serverId: server.id },
    });

    if (!row) {
      return { state: null, updated_at: null };
    }

    return {
      state: row.state as Record<string, unknown>,
      updated_at: row.updatedAt.toISOString(),
    };
  }

  /**
   * Persists a state snapshot for the given server (upsert).
   * Validates the bearer token against the server's stored linkToken.
   * Throws NotFoundException if the server is not registered.
   * Throws ForbiddenException if the token is missing or does not match.
   */
  async saveState(
    serverLogin: string,
    snapshot: ServerStateSnapshotDto,
    bearerToken: string | undefined,
  ): Promise<SaveStateResponse> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    // Validate link_bearer token.
    if (!bearerToken || !server.linkToken || bearerToken !== server.linkToken) {
      throw new ForbiddenException('Invalid or missing link bearer token.');
    }

    const row = await this.prisma.serverState.upsert({
      where: { serverId: server.id },
      create: {
        serverId: server.id,
        state: snapshot as unknown as Prisma.InputJsonValue,
      },
      update: {
        state: snapshot as unknown as Prisma.InputJsonValue,
      },
    });

    return {
      saved: true,
      updated_at: row.updatedAt.toISOString(),
    };
  }
}
