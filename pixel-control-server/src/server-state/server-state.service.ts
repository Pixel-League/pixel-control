import { ForbiddenException, Injectable } from '@nestjs/common';
import { Prisma } from '@prisma/client';

import { PrismaService } from '../prisma/prisma.service';
import { ServerResolverService } from '../common/services/server-resolver.service';
import { ConfigTemplateService } from '../config-template/config-template.service';
import { ServerStateSnapshotDto, GetStateResponse, SaveStateResponse } from './dto/server-state.dto';

/**
 * Service for persisting and retrieving plugin state snapshots per server.
 *
 * Each server has at most one state row (one-to-one via serverId @unique).
 * State is stored as a JSON blob — no schema normalization.
 *
 * Auth validation: compares the provided bearer token against the server's
 * stored linkToken. Throws ForbiddenException if they do not match.
 *
 * Template fallback: when no saved state exists and the server has a linked
 * config template, returns the template config wrapped in a snapshot envelope.
 */
@Injectable()
export class ServerStateService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly serverResolver: ServerResolverService,
    private readonly configTemplateService: ConfigTemplateService,
  ) {}

  /**
   * Returns the persisted state snapshot for the given server.
   * Falls back to linked template config if no saved state exists.
   * Returns null state if neither saved state nor template exist.
   * Throws NotFoundException if the server is not registered.
   */
  async getState(serverLogin: string): Promise<GetStateResponse> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    const row = await this.prisma.serverState.findUnique({
      where: { serverId: server.id },
    });

    if (row) {
      return {
        state: row.state as Record<string, unknown>,
        updated_at: row.updatedAt.toISOString(),
        source: 'saved',
      };
    }

    // Template fallback
    const templateData = await this.configTemplateService.getTemplateConfigForServer(server.id);
    if (templateData) {
      const snapshot: Record<string, unknown> = {
        state_version: '1.0',
        captured_at: Math.floor(Date.now() / 1000),
        admin: templateData.config,
        veto_draft: {
          session: null,
          matchmaking_ready_armed: false,
          votes: {},
        },
      };

      return {
        state: snapshot,
        updated_at: null,
        source: 'template',
      };
    }

    return { state: null, updated_at: null, source: 'default' };
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

  /**
   * Applies the linked template config as the server's saved state.
   * Delegates to ConfigTemplateService for template resolution and
   * builds a full snapshot envelope before persisting.
   */
  async applyTemplate(
    serverLogin: string,
  ): Promise<{ applied: true; template_id: string; template_name: string; updated_at: string }> {
    const templateData = await this.configTemplateService.getTemplateForApply(serverLogin);

    const snapshot = {
      state_version: '1.0',
      captured_at: Math.floor(Date.now() / 1000),
      admin: templateData.config,
      veto_draft: {
        session: null,
        matchmaking_ready_armed: false,
        votes: {},
      },
    };

    const row = await this.prisma.serverState.upsert({
      where: { serverId: templateData.serverId },
      create: {
        serverId: templateData.serverId,
        state: snapshot as unknown as Prisma.InputJsonValue,
      },
      update: {
        state: snapshot as unknown as Prisma.InputJsonValue,
      },
    });

    return {
      applied: true,
      template_id: templateData.templateId,
      template_name: templateData.templateName,
      updated_at: row.updatedAt.toISOString(),
    };
  }
}
