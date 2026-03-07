const IDEMPOTENT_METHODS = new Set(['GET', 'PUT', 'DELETE', 'HEAD', 'OPTIONS']);
const MAX_RETRIES = 3;
const BASE_DELAY_MS = 200;

/**
 * Fetch wrapper with retry logic for network errors and 5xx responses.
 *
 * - Retries up to 3 times with exponential backoff (200ms, 400ms, 800ms).
 * - Only retries idempotent methods (GET, PUT, DELETE, HEAD, OPTIONS).
 * - Never retries POST or PATCH to avoid duplicate side effects.
 */
export async function fetchWithRetry(
  input: RequestInfo | URL,
  init?: RequestInit,
): Promise<Response> {
  const method = (init?.method ?? 'GET').toUpperCase();
  const canRetry = IDEMPOTENT_METHODS.has(method);

  let lastError: unknown;

  for (let attempt = 0; attempt <= (canRetry ? MAX_RETRIES : 0); attempt++) {
    try {
      const response = await fetch(input, init);

      if (canRetry && attempt < MAX_RETRIES && response.status >= 500) {
        await delay(BASE_DELAY_MS * Math.pow(2, attempt));
        continue;
      }

      return response;
    } catch (error) {
      lastError = error;

      if (!canRetry || attempt >= MAX_RETRIES) {
        throw error;
      }

      await delay(BASE_DELAY_MS * Math.pow(2, attempt));
    }
  }

  throw lastError;
}

function delay(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
