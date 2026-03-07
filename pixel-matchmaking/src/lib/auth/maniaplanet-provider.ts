import type { OAuthConfig, OAuthUserConfig } from "next-auth/providers";

/**
 * Profile returned by the ManiaPlanet `/webservices/me` endpoint.
 */
export interface ManiaPlanetProfile {
  /** Unique, immutable ManiaPlanet username. */
  login: string;
  /** Display name (may contain ManiaPlanet formatting codes). */
  nickname: string;
  /** Geographic zone path, e.g. "World|Europe|France". */
  path: string;
}

/**
 * Custom Auth.js v5 OAuth2 provider for ManiaPlanet SSO.
 *
 * Endpoints:
 * - Authorize: https://www.maniaplanet.com/login/oauth2/authorize
 * - Token:     https://www.maniaplanet.com/login/oauth2/access_token
 * - Profile:   https://www.maniaplanet.com/webservices/me
 *
 * Scope: "basic" (space separator).
 * Profile returns: login, nickname, path — no email.
 *
 * Reference: ressources/oauth2-maniaplanet/src/Provider/Maniaplanet.php
 */
export default function ManiaPlanet(
  config: OAuthUserConfig<ManiaPlanetProfile>,
): OAuthConfig<ManiaPlanetProfile> {
  return {
    id: "maniaplanet",
    name: "ManiaPlanet",
    type: "oauth",
    clientId: config.clientId,
    clientSecret: config.clientSecret,
    authorization: {
      url: "https://www.maniaplanet.com/login/oauth2/authorize",
      params: { scope: "basic" },
    },
    token: {
      url: "https://www.maniaplanet.com/login/oauth2/access_token",
    },
    userinfo: {
      url: "https://www.maniaplanet.com/webservices/me",
    },
    profile(profile: ManiaPlanetProfile) {
      return {
        id: profile.login,
        name: profile.nickname,
        email: `${profile.login}@maniaplanet.local`,
        image: null,
        login: profile.login,
        nickname: profile.nickname,
        path: profile.path,
      };
    },
  } satisfies OAuthConfig<ManiaPlanetProfile>;
}
