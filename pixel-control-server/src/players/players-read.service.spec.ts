import { NotFoundException } from '@nestjs/common';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PlayersReadService } from './players-read.service';

const makeServer = (overrides = {}) => ({
  id: 'server-uuid',
  serverLogin: 'test-server',
  gameMode: 'Elite',
  titleId: 'SMStormElite@nadeolabs',
  ...overrides,
});

const makePlayerEvent = (overrides: {
  login: string;
  eventKind?: string;
  sourceTime?: bigint;
  connected?: boolean;
  spectator?: boolean;
  teamId?: number;
} & Record<string, unknown>) => ({
  id: 'evt-' + overrides.login,
  eventId: 'pc-evt-player-' + overrides.login,
  eventName: 'pixel_control.player.something',
  eventCategory: 'player',
  sourceCallback: 'SM_PLAYER',
  sourceTime: overrides.sourceTime ?? BigInt(1000000),
  idempotencyKey: 'key-' + overrides.login,
  schemaVersion: '2026-02-20.1',
  receivedAt: new Date('2026-02-28T10:00:00Z'),
  metadata: null,
  payload: {
    event_kind: overrides.eventKind ?? 'player.connect',
    player: {
      login: overrides.login,
      nickname: 'Nickname-' + overrides.login,
      team_id: overrides.teamId ?? 0,
      is_spectator: overrides.spectator ?? false,
      is_connected: overrides.connected ?? true,
      has_joined_game: true,
      auth_level: 0,
      auth_name: 'player',
    },
    state_delta: {
      connectivity_state: 'connected',
      readiness_state: 'ready',
      eligibility_state: 'eligible',
    },
  },
});

const makeResolverStub = (server = makeServer()) => ({
  resolve: vi.fn().mockResolvedValue({ server, online: true }),
});

const makePrismaStub = () => ({
  event: {
    findMany: vi.fn().mockResolvedValue([]),
  },
});

describe('PlayersReadService', () => {
  let service: PlayersReadService;
  let resolver: ReturnType<typeof makeResolverStub>;
  let prisma: ReturnType<typeof makePrismaStub>;

  beforeEach(() => {
    resolver = makeResolverStub();
    prisma = makePrismaStub();
    service = new PlayersReadService(resolver as never, prisma as never);
  });

  describe('getPlayers', () => {
    it('returns empty list when no player events', async () => {
      prisma.event.findMany.mockResolvedValue([]);

      const result = await service.getPlayers('test-server', 50, 0);

      expect(result.data).toHaveLength(0);
      expect(result.pagination.total).toBe(0);
    });

    it('returns de-duplicated player list (newest event per login)', async () => {
      const events = [
        makePlayerEvent({ login: 'player1', sourceTime: BigInt(2000000) }),
        makePlayerEvent({ login: 'player2', sourceTime: BigInt(1500000) }),
        makePlayerEvent({ login: 'player1', sourceTime: BigInt(1000000) }), // older, should be ignored
      ];
      prisma.event.findMany.mockResolvedValue(events);

      const result = await service.getPlayers('test-server', 50, 0);

      expect(result.pagination.total).toBe(2);
      expect(result.data.map((p) => p.login)).toContain('player1');
      expect(result.data.map((p) => p.login)).toContain('player2');
    });

    it('marks disconnected players with is_connected=false', async () => {
      const events = [
        makePlayerEvent({ login: 'player1', eventKind: 'player.disconnect', sourceTime: BigInt(2000000) }),
      ];
      prisma.event.findMany.mockResolvedValue(events);

      const result = await service.getPlayers('test-server', 50, 0);

      expect(result.data[0]!.is_connected).toBe(false);
    });

    it('respects pagination offset and limit', async () => {
      const events = [
        makePlayerEvent({ login: 'a', sourceTime: BigInt(3000000) }),
        makePlayerEvent({ login: 'b', sourceTime: BigInt(2000000) }),
        makePlayerEvent({ login: 'c', sourceTime: BigInt(1000000) }),
      ];
      prisma.event.findMany.mockResolvedValue(events);

      const result = await service.getPlayers('test-server', 2, 1);

      expect(result.pagination.total).toBe(3);
      expect(result.data).toHaveLength(2);
    });

    it('extracts connectivity and readiness state from state_delta', async () => {
      prisma.event.findMany.mockResolvedValue([
        makePlayerEvent({ login: 'p1', sourceTime: BigInt(1000000) }),
      ]);

      const result = await service.getPlayers('test-server', 50, 0);

      expect(result.data[0]!.connectivity_state).toBe('connected');
      expect(result.data[0]!.readiness_state).toBe('ready');
      expect(result.data[0]!.eligibility_state).toBe('eligible');
    });
  });

  describe('getPlayer', () => {
    it('returns full player state for known player', async () => {
      prisma.event.findMany.mockResolvedValue([
        makePlayerEvent({ login: 'player1', sourceTime: BigInt(1000000) }),
      ]);

      const result = await service.getPlayer('test-server', 'player1');

      expect(result.login).toBe('player1');
      expect(result.nickname).toBe('Nickname-player1');
      expect(result.is_connected).toBe(true);
      expect(result.last_event_id).toBe('pc-evt-player-player1');
    });

    it('throws NotFoundException for unknown player login', async () => {
      prisma.event.findMany.mockResolvedValue([
        makePlayerEvent({ login: 'player1', sourceTime: BigInt(1000000) }),
      ]);

      await expect(
        service.getPlayer('test-server', 'unknown-player'),
      ).rejects.toThrow(NotFoundException);
    });

    it('returns is_connected=false for disconnected player', async () => {
      prisma.event.findMany.mockResolvedValue([
        makePlayerEvent({ login: 'p1', eventKind: 'player.disconnect', sourceTime: BigInt(1000000) }),
      ]);

      const result = await service.getPlayer('test-server', 'p1');

      expect(result.is_connected).toBe(false);
    });
  });
});
