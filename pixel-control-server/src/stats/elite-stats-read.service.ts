import { Injectable, Logger, NotFoundException } from '@nestjs/common';

import {
  EliteClutchInfo,
  EliteTurnPlayerStats,
  EliteTurnSummary,
} from '../common/interfaces/elite-context.interface';
import { PaginatedResponse, paginate } from '../common/dto/read-response.dto';
import { ServerResolverService } from '../common/services/server-resolver.service';
import { PrismaService } from '../prisma/prisma.service';

// ---------------------------------------------------------------------------
// Response shapes
// ---------------------------------------------------------------------------

export interface EliteTurnListResponse {
  server_login: string;
  turns: EliteTurnSummary[];
  pagination: {
    total: number;
    limit: number;
    offset: number;
  };
}

export interface EliteTurnDetailResponse extends EliteTurnSummary {
  server_login: string;
  event_id: string;
  recorded_at: string;
}

export interface EliteClutchTurnRef {
  turn_number: number;
  map_uid: string;
  map_name: string;
  recorded_at: string;
  defender_logins: string[];
  alive_defenders_at_end: number;
  total_defenders: number;
  outcome: string;
}

export interface PlayerClutchStatsResponse {
  server_login: string;
  player_login: string;
  clutch_count: number;
  total_defense_rounds: number;
  clutch_rate: number;
  clutch_turns: EliteClutchTurnRef[];
}

export interface PlayerEliteTurnEntry {
  turn_number: number;
  map_uid: string;
  map_name: string;
  recorded_at: string;
  role: 'attacker' | 'defender';
  stats: EliteTurnPlayerStats;
  outcome: string;
  defense_success: boolean;
  clutch: EliteClutchInfo;
}

export interface PlayerEliteTurnHistoryResponse extends PaginatedResponse<PlayerEliteTurnEntry> {
  server_login: string;
  player_login: string;
}

// ---------------------------------------------------------------------------
// Internal raw type for Event rows
// ---------------------------------------------------------------------------

type CombatEventRow = {
  id: string;
  eventId: string;
  payload: unknown;
  sourceTime: bigint;
};

@Injectable()
export class EliteStatsReadService {
  private readonly logger = new Logger(EliteStatsReadService.name);

  constructor(
    private readonly serverResolver: ServerResolverService,
    private readonly prisma: PrismaService,
  ) {}

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  private isEliteTurnSummaryPayload(payload: unknown): payload is EliteTurnSummary {
    if (!payload || typeof payload !== 'object') return false;
    const p = payload as Record<string, unknown>;
    return p['event_kind'] === 'elite_turn_summary';
  }

  private async fetchEliteTurnEvents(
    serverId: string,
    take: number,
    sinceMs?: bigint,
    untilMs?: bigint,
  ): Promise<CombatEventRow[]> {
    const rows = await this.prisma.event.findMany({
      where: {
        serverId,
        eventCategory: 'combat',
        ...(sinceMs !== undefined || untilMs !== undefined
          ? {
              sourceTime: {
                ...(sinceMs !== undefined ? { gte: sinceMs } : {}),
                ...(untilMs !== undefined ? { lte: untilMs } : {}),
              },
            }
          : {}),
      },
      orderBy: { sourceTime: 'desc' },
      take,
    });

    return (rows as CombatEventRow[]).filter((row) =>
      this.isEliteTurnSummaryPayload(row.payload),
    );
  }

  // ---------------------------------------------------------------------------
  // Public methods
  // ---------------------------------------------------------------------------

  /**
   * List elite turn summaries for a server, paginated and optionally time-filtered.
   */
  async getEliteTurns(
    serverLogin: string,
    limit: number,
    offset: number,
    since?: string,
    until?: string,
  ): Promise<EliteTurnListResponse> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    const sinceMs = since ? BigInt(new Date(since).getTime()) : undefined;
    const untilMs = until ? BigInt(new Date(until).getTime()) : undefined;

    const rows = await this.fetchEliteTurnEvents(server.id, 2000, sinceMs, untilMs);

    const total = rows.length;
    const page = rows.slice(offset, offset + limit);
    const turns = page.map((row) => row.payload as EliteTurnSummary);

    return {
      server_login: serverLogin,
      turns,
      pagination: { total, limit, offset },
    };
  }

  /**
   * Get a single elite turn summary by turn number.
   * Returns the most recent event with that turn number (in case of duplicates after server reset).
   */
  async getEliteTurnByNumber(
    serverLogin: string,
    turnNumber: number,
  ): Promise<EliteTurnDetailResponse> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    const rows = await this.fetchEliteTurnEvents(server.id, 5000);

    const match = rows.find((row) => {
      const payload = row.payload as EliteTurnSummary;
      return payload.turn_number === turnNumber;
    });

    if (!match) {
      throw new NotFoundException(
        `No elite turn #${turnNumber} found on server '${serverLogin}'`,
      );
    }

    const payload = match.payload as EliteTurnSummary;
    return {
      ...payload,
      server_login: serverLogin,
      event_id: match.eventId,
      recorded_at: new Date(Number(match.sourceTime)).toISOString(),
    };
  }

  /**
   * Get clutch statistics for a specific player across all stored turn summaries.
   */
  async getPlayerClutchStats(
    serverLogin: string,
    playerLogin: string,
  ): Promise<PlayerClutchStatsResponse> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    const rows = await this.fetchEliteTurnEvents(server.id, 5000);

    let totalDefenseRounds = 0;
    const clutchTurns: EliteClutchTurnRef[] = [];

    for (const row of rows) {
      const payload = row.payload as EliteTurnSummary;
      const isDefender = payload.defender_logins.includes(playerLogin);
      if (!isDefender) continue;

      totalDefenseRounds++;

      if (
        payload.clutch.is_clutch &&
        payload.clutch.clutch_player_login === playerLogin
      ) {
        clutchTurns.push({
          turn_number: payload.turn_number,
          map_uid: payload.map_uid,
          map_name: payload.map_name,
          recorded_at: new Date(Number(row.sourceTime)).toISOString(),
          defender_logins: payload.defender_logins,
          alive_defenders_at_end: payload.clutch.alive_defenders_at_end,
          total_defenders: payload.clutch.total_defenders,
          outcome: payload.outcome,
        });
      }
    }

    const clutchCount = clutchTurns.length;
    const clutchRate =
      totalDefenseRounds > 0
        ? Math.round((clutchCount / totalDefenseRounds) * 10000) / 10000
        : 0;

    return {
      server_login: serverLogin,
      player_login: playerLogin,
      clutch_count: clutchCount,
      total_defense_rounds: totalDefenseRounds,
      clutch_rate: clutchRate,
      clutch_turns: clutchTurns,
    };
  }

  /**
   * Get per-turn Elite history for a specific player (turns they participated in as attacker or defender).
   */
  async getElitePlayerTurnHistory(
    serverLogin: string,
    playerLogin: string,
    limit: number,
    offset: number,
  ): Promise<PlayerEliteTurnHistoryResponse> {
    const { server } = await this.serverResolver.resolve(serverLogin);

    const rows = await this.fetchEliteTurnEvents(server.id, 5000);

    const entries: PlayerEliteTurnEntry[] = [];

    for (const row of rows) {
      const payload = row.payload as EliteTurnSummary;
      const isAttacker = payload.attacker_login === playerLogin;
      const isDefender = payload.defender_logins.includes(playerLogin);

      if (!isAttacker && !isDefender) continue;

      const rawStats = payload.per_player_stats[playerLogin];
      const stats: EliteTurnPlayerStats = rawStats ?? {
        kills: 0,
        deaths: 0,
        hits: 0,
        shots: 0,
        misses: 0,
        rocket_hits: 0,
      };

      entries.push({
        turn_number: payload.turn_number,
        map_uid: payload.map_uid,
        map_name: payload.map_name,
        recorded_at: new Date(Number(row.sourceTime)).toISOString(),
        role: isAttacker ? 'attacker' : 'defender',
        stats,
        outcome: payload.outcome,
        defense_success: payload.defense_success,
        clutch: payload.clutch,
      });
    }

    const paginated = paginate(entries, limit, offset);

    return {
      server_login: serverLogin,
      player_login: playerLogin,
      ...paginated,
    };
  }
}
