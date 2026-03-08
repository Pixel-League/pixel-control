/**
 * Application configuration — typed accessor for environment variables.
 *
 * NEXT_PUBLIC_ vars are available on both client and server.
 * Other vars are server-only (undefined on client bundles).
 */
export const config = {
  /** Pixel Control Server API base URL (public, available client-side) */
  apiUrl: process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:3000/v1',

  /** Auth.js v5 — ManiaPlanet OAuth2 (server-only) */
  auth: {
    secret: process.env.AUTH_SECRET ?? '',
    maniaplanetId: process.env.AUTH_MANIAPLANET_ID ?? '',
    maniaplanetSecret: process.env.AUTH_MANIAPLANET_SECRET ?? '',
    url: process.env.AUTH_URL ?? 'http://localhost:4000',
  },
} as const;
