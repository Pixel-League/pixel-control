import { PixelControlApi } from './generated';
import { config } from '@/shared/lib/config';

/**
 * Pre-configured Pixel Control API client instance.
 *
 * Uses the generated SDK with:
 * - Base URL from environment (NEXT_PUBLIC_API_URL)
 * - Auth token injection placeholder (for future session-based auth)
 * - Default headers
 */
export const apiClient = new PixelControlApi({
  BASE: config.apiUrl,
  HEADERS: {
    'Accept': 'application/json',
  },
});

/**
 * Create a new API client with a custom auth token.
 * Used for server-side calls where session token is available.
 */
export function createApiClient(token?: string): PixelControlApi {
  return new PixelControlApi({
    BASE: config.apiUrl,
    TOKEN: token,
    HEADERS: {
      'Accept': 'application/json',
    },
  });
}
