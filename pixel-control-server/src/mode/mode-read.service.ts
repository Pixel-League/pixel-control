import { Injectable, Logger } from '@nestjs/common';

import { ServerResolverService } from '../common/services/server-resolver.service';
import { PrismaService } from '../prisma/prisma.service';

export interface ModeEventEntry {
  event_name: string;
  event_id: string;
  source_callback: string;
  source_time: string;
  raw_callback_summary: Record<string, unknown> | null;
}

export interface ModeResponse {
  server_login: string;
  game_mode: string | null;
  title_id: string | null;
  recent_mode_events: ModeEventEntry[];
  total_mode_events: number;
  last_updated: string | null;
}

interface RawModePayload {
  raw_callback_summary?: Record<string, unknown>;
  [key: string]: unknown;
}

@Injectable()
export class ModeReadService {
  private readonly logger = new Logger(ModeReadService.name);

  constructor(
    private readonly serverResolver: ServerResolverService,
    private readonly prisma: PrismaService,
  ) {}

  async getModeData(serverLogin: string, limit: number): Promise<ModeResponse> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    const [recentEvents, totalCount] = await Promise.all([
      this.prisma.event.findMany({
        where: { serverId: server.id, eventCategory: 'mode' },
        orderBy: { sourceTime: 'desc' },
        take: limit,
      }),
      this.prisma.event.count({
        where: { serverId: server.id, eventCategory: 'mode' },
      }),
    ]);

    const modeEvents: ModeEventEntry[] = recentEvents.map((event) => {
      const payload = event.payload as RawModePayload;
      return {
        event_name: event.eventName,
        event_id: event.eventId,
        source_callback: event.sourceCallback,
        source_time: new Date(Number(event.sourceTime)).toISOString(),
        raw_callback_summary: payload?.raw_callback_summary ?? null,
      };
    });

    const lastEvent = recentEvents[0];

    return {
      server_login: serverLogin,
      game_mode: server.gameMode ?? null,
      title_id: server.titleId ?? null,
      recent_mode_events: modeEvents,
      total_mode_events: totalCount,
      last_updated: lastEvent
        ? new Date(Number(lastEvent.sourceTime)).toISOString()
        : null,
    };
  }
}
