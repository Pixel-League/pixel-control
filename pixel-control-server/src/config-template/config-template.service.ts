import {
  ConflictException,
  Injectable,
  NotFoundException,
  BadRequestException,
} from '@nestjs/common';
import { Prisma } from '@prisma/client';

import { PrismaService } from '../prisma/prisma.service';
import { ServerResolverService } from '../common/services/server-resolver.service';
import {
  CreateConfigTemplateDto,
  UpdateConfigTemplateDto,
  ConfigTemplateResponse,
  ConfigTemplateListResponse,
} from './dto/config-template.dto';

@Injectable()
export class ConfigTemplateService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly serverResolver: ServerResolverService,
  ) {}

  // ─── CRUD ─────────────────────────────────────────────────────────────────

  async create(dto: CreateConfigTemplateDto): Promise<ConfigTemplateResponse> {
    try {
      const template = await this.prisma.configTemplate.create({
        data: {
          name: dto.name,
          description: dto.description ?? null,
          config: dto.config as unknown as Prisma.InputJsonValue,
        },
        include: { _count: { select: { servers: true } } },
      });

      return this.toResponse(template);
    } catch (err) {
      if (
        err instanceof Prisma.PrismaClientKnownRequestError &&
        err.code === 'P2002'
      ) {
        throw new ConflictException(`Template name '${dto.name}' is already in use.`);
      }
      throw err;
    }
  }

  async findAll(): Promise<ConfigTemplateListResponse> {
    const templates = await this.prisma.configTemplate.findMany({
      include: { _count: { select: { servers: true } } },
      orderBy: { createdAt: 'desc' },
    });

    return templates.map((t) => this.toResponse(t));
  }

  async findOne(id: string): Promise<ConfigTemplateResponse> {
    const template = await this.prisma.configTemplate.findUnique({
      where: { id },
      include: { _count: { select: { servers: true } } },
    });

    if (!template) {
      throw new NotFoundException(`Config template '${id}' not found.`);
    }

    return this.toResponse(template);
  }

  async update(id: string, dto: UpdateConfigTemplateDto): Promise<ConfigTemplateResponse> {
    // Verify existence first
    const existing = await this.prisma.configTemplate.findUnique({ where: { id } });
    if (!existing) {
      throw new NotFoundException(`Config template '${id}' not found.`);
    }

    try {
      const data: Prisma.ConfigTemplateUpdateInput = {};
      if (dto.name !== undefined) data.name = dto.name;
      if (dto.description !== undefined) data.description = dto.description;
      if (dto.config !== undefined) data.config = dto.config as unknown as Prisma.InputJsonValue;

      const template = await this.prisma.configTemplate.update({
        where: { id },
        data,
        include: { _count: { select: { servers: true } } },
      });

      return this.toResponse(template);
    } catch (err) {
      if (
        err instanceof Prisma.PrismaClientKnownRequestError &&
        err.code === 'P2002'
      ) {
        throw new ConflictException(`Template name '${dto.name}' is already in use.`);
      }
      throw err;
    }
  }

  async remove(id: string): Promise<{ deleted: true }> {
    const template = await this.prisma.configTemplate.findUnique({
      where: { id },
      include: { _count: { select: { servers: true } } },
    });

    if (!template) {
      throw new NotFoundException(`Config template '${id}' not found.`);
    }

    if (template._count.servers > 0) {
      throw new ConflictException(
        `Cannot delete template '${template.name}': ${template._count.servers} server(s) are still linked.`,
      );
    }

    await this.prisma.configTemplate.delete({ where: { id } });
    return { deleted: true };
  }

  // ─── Server-template association ──────────────────────────────────────────

  async linkServerToTemplate(
    serverLogin: string,
    templateId: string,
  ): Promise<{ linked: true; template_id: string; template_name: string }> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    const template = await this.prisma.configTemplate.findUnique({
      where: { id: templateId },
    });
    if (!template) {
      throw new NotFoundException(`Config template '${templateId}' not found.`);
    }

    await this.prisma.server.update({
      where: { id: server.id },
      data: { configTemplateId: templateId },
    });

    return { linked: true, template_id: template.id, template_name: template.name };
  }

  async unlinkServer(serverLogin: string): Promise<{ unlinked: true }> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    await this.prisma.server.update({
      where: { id: server.id },
      data: { configTemplateId: null },
    });

    return { unlinked: true };
  }

  async getServerTemplate(
    serverLogin: string,
  ): Promise<{ template: ConfigTemplateResponse | null }> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    if (!server.configTemplateId) {
      return { template: null };
    }

    const template = await this.prisma.configTemplate.findUnique({
      where: { id: server.configTemplateId },
      include: { _count: { select: { servers: true } } },
    });

    if (!template) {
      return { template: null };
    }

    return { template: this.toResponse(template) };
  }

  // ─── Template resolution (for ServerStateService) ─────────────────────────

  /**
   * Returns the template config for a server, or null if no template is linked.
   * Used by ServerStateService for template fallback in GET /state.
   */
  async getTemplateConfigForServer(
    serverId: string,
  ): Promise<{ config: Record<string, unknown>; templateId: string; templateName: string } | null> {
    const server = await this.prisma.server.findUnique({
      where: { id: serverId },
      include: { configTemplate: true },
    });

    if (!server?.configTemplateId || !server.configTemplate) {
      return null;
    }

    return {
      config: server.configTemplate.config as Record<string, unknown>,
      templateId: server.configTemplate.id,
      templateName: server.configTemplate.name,
    };
  }

  // ─── Apply template ───────────────────────────────────────────────────────

  /**
   * Returns the full template config for a server to be applied as saved state.
   * Throws 404 if server not found, 400 if no template is linked.
   */
  async getTemplateForApply(
    serverLogin: string,
  ): Promise<{ config: Record<string, unknown>; templateId: string; templateName: string; serverId: string }> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    if (!server.configTemplateId) {
      throw new BadRequestException(`Server '${serverLogin}' has no linked config template.`);
    }

    const template = await this.prisma.configTemplate.findUnique({
      where: { id: server.configTemplateId },
    });

    if (!template) {
      throw new NotFoundException(`Linked template '${server.configTemplateId}' no longer exists.`);
    }

    return {
      config: template.config as Record<string, unknown>,
      templateId: template.id,
      templateName: template.name,
      serverId: server.id,
    };
  }

  // ─── Internal helpers ─────────────────────────────────────────────────────

  private toResponse(
    template: {
      id: string;
      name: string;
      description: string | null;
      config: unknown;
      createdAt: Date;
      updatedAt: Date;
      _count: { servers: number };
    },
  ): ConfigTemplateResponse {
    return {
      id: template.id,
      name: template.name,
      description: template.description,
      config: template.config as Record<string, unknown>,
      server_count: template._count.servers,
      created_at: template.createdAt.toISOString(),
      updated_at: template.updatedAt.toISOString(),
    };
  }
}
