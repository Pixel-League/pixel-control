/**
 * Server-side ManiaPlanet API client.
 * Uses app credentials (Basic auth) to fetch player profiles.
 * Never import this file in client components.
 */

export interface MpPlayerProfile {
  login: string;
  nickname: string;
  path: string | null;
}

/**
 * Fetches a ManiaPlanet player profile by login from the public web services API.
 * Uses Basic authentication with the configured app credentials.
 * Returns null if the player is not found or the request fails.
 */
export async function getMpPlayerProfile(login: string): Promise<MpPlayerProfile | null> {
  const clientId = process.env.AUTH_MANIAPLANET_ID;
  const clientSecret = process.env.AUTH_MANIAPLANET_SECRET;

  if (!clientId || !clientSecret) {
    return null;
  }

  const credentials = Buffer.from(`${clientId}:${clientSecret}`).toString('base64');

  try {
    const response = await fetch(
      `https://www.maniaplanet.com/webservices/players/${encodeURIComponent(login)}`,
      {
        headers: {
          Authorization: `Basic ${credentials}`,
          Accept: 'application/json',
        },
        signal: AbortSignal.timeout(5000),
      },
    );

    if (!response.ok) {
      return null;
    }

    const data = await response.json() as { login?: string; nickname?: string; path?: string };

    if (!data.login) {
      return null;
    }

    return {
      login: data.login,
      nickname: data.nickname ?? data.login,
      path: data.path ?? null,
    };
  } catch {
    return null;
  }
}
