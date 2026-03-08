import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mocks must use vi.fn() inline (not referencing outer const) due to vi.mock hoisting
vi.mock('@/shared/lib/prisma', () => ({
  prisma: {
    user: { findUnique: vi.fn() },
  },
}));

vi.mock('@/shared/lib/mp-api', () => ({
  getMpPlayerProfile: vi.fn(),
}));

// Imports after mocks to get the mocked versions
import { prisma } from '@/shared/lib/prisma';
import { getMpPlayerProfile } from '@/shared/lib/mp-api';
import { getPlayerNickname, getPlayerProfile } from './players';

const mockFindUnique = prisma.user.findUnique as ReturnType<typeof vi.fn>;
const mockGetMpPlayerProfile = getMpPlayerProfile as ReturnType<typeof vi.fn>;

describe('getPlayerNickname', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('returns stripped nickname from DB when user is found', async () => {
    mockFindUnique.mockResolvedValue({ nickname: '$o$n$fftop' });

    const result = await getPlayerNickname('onepiece2000');

    // tm-text strips $o$n$f (style + 1-digit color attempt), leaving 'ftop'
    expect(result).toBe('ftop');
    expect(mockGetMpPlayerProfile).not.toHaveBeenCalled();
  });

  it('falls back to ManiaPlanet API when user is not in DB', async () => {
    mockFindUnique.mockResolvedValue(null);
    mockGetMpPlayerProfile.mockResolvedValue({
      login: 'someone',
      nickname: '$f00Red',
      path: null,
    });

    const result = await getPlayerNickname('someone');

    expect(result).toBe('Red');
    expect(mockGetMpPlayerProfile).toHaveBeenCalledWith('someone');
  });

  it('returns raw login when DB and API both fail', async () => {
    mockFindUnique.mockResolvedValue(null);
    mockGetMpPlayerProfile.mockResolvedValue(null);

    const result = await getPlayerNickname('unknown-player');

    expect(result).toBe('unknown-player');
  });
});

describe('getPlayerProfile', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('returns raw profile from DB (nickname not stripped)', async () => {
    mockFindUnique.mockResolvedValue({
      login: 'onepiece2000',
      nickname: '$o$n$fftop',
      path: 'World|Europe|France',
    });

    const result = await getPlayerProfile('onepiece2000');

    expect(result).toEqual({
      login: 'onepiece2000',
      nickname: '$o$n$fftop',
      path: 'World|Europe|France',
    });
    expect(mockGetMpPlayerProfile).not.toHaveBeenCalled();
  });

  it('falls back to ManiaPlanet API when user is not in DB', async () => {
    mockFindUnique.mockResolvedValue(null);
    mockGetMpPlayerProfile.mockResolvedValue({
      login: 'someone',
      nickname: '$fffSomeone',
      path: null,
    });

    const result = await getPlayerProfile('someone');

    expect(result).toEqual({ login: 'someone', nickname: '$fffSomeone', path: null });
  });

  it('returns null when both DB and API return nothing', async () => {
    mockFindUnique.mockResolvedValue(null);
    mockGetMpPlayerProfile.mockResolvedValue(null);

    const result = await getPlayerProfile('ghost');

    expect(result).toBeNull();
  });
});
