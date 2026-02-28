import { Injectable, Logger } from '@nestjs/common';

import { ServerResolverService } from '../common/services/server-resolver.service';
import { PrismaService } from '../prisma/prisma.service';

export interface MapEntry {
  uid: string;
  name: string;
  file?: string;
  [key: string]: unknown;
}

export interface MapsResponse {
  server_login: string;
  maps: MapEntry[];
  map_count: number;
  current_map: Record<string, unknown> | null;
  current_map_index: number | null;
  last_updated: string | null;
}

interface RawLifecyclePayload {
  map_rotation?: {
    map_pool?: MapEntry[];
    current_map?: Record<string, unknown>;
    current_map_index?: number;
  };
}

@Injectable()
export class MapsReadService {
  private readonly logger = new Logger(MapsReadService.name);

  constructor(
    private readonly serverResolver: ServerResolverService,
    private readonly prisma: PrismaService,
  ) {}

  async getMaps(serverLogin: string): Promise<MapsResponse> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    const events = await this.prisma.event.findMany({
      where: { serverId: server.id, eventCategory: 'lifecycle' },
      orderBy: { sourceTime: 'desc' },
      take: 200,
    });

    // Find the latest lifecycle event with map_rotation data
    const rotationEvent = events.find((e) => {
      const payload = e.payload as RawLifecyclePayload;
      return payload?.map_rotation?.map_pool != null;
    });

    if (!rotationEvent) {
      return {
        server_login: serverLogin,
        maps: [],
        map_count: 0,
        current_map: null,
        current_map_index: null,
        last_updated: null,
      };
    }

    const payload = rotationEvent.payload as RawLifecyclePayload;
    const mapPool = payload.map_rotation!.map_pool ?? [];

    return {
      server_login: serverLogin,
      maps: mapPool,
      map_count: mapPool.length,
      current_map: payload.map_rotation!.current_map ?? null,
      current_map_index: payload.map_rotation!.current_map_index ?? null,
      last_updated: new Date(Number(rotationEvent.sourceTime)).toISOString(),
    };
  }
}
