'use server';

import { prisma } from '@/shared/lib/prisma';
import { getMpPlayerProfile, type MpPlayerProfile } from '@/shared/lib/mp-api';
import { stripMpStyles } from '@/shared/lib/mp-text';

/**
 * Returns the stripped (plain-text) nickname of a player by their ManiaPlanet login.
 *
 * Resolution order:
 * 1. Local DB (users who have logged in to this platform)
 * 2. ManiaPlanet web services API (public profiles)
 * 3. Raw login as fallback
 */
export async function getPlayerNickname(login: string): Promise<string> {
  const dbUser = await prisma.user.findUnique({
    where: { login },
    select: { nickname: true },
  });

  if (dbUser?.nickname) {
    return stripMpStyles(dbUser.nickname);
  }

  const mpProfile = await getMpPlayerProfile(login);
  if (mpProfile?.nickname) {
    return stripMpStyles(mpProfile.nickname);
  }

  return login;
}

/**
 * Returns the raw profile (nickname NOT stripped) of a player by login.
 * Useful when the caller needs to render the formatted nickname (e.g. with MpNickname).
 *
 * Resolution order: local DB → ManiaPlanet API → null
 */
export async function getPlayerProfile(login: string): Promise<MpPlayerProfile | null> {
  const dbUser = await prisma.user.findUnique({
    where: { login },
    select: { login: true, nickname: true, path: true },
  });

  if (dbUser) {
    return {
      login: dbUser.login,
      nickname: dbUser.nickname,
      path: dbUser.path,
    };
  }

  return getMpPlayerProfile(login);
}
