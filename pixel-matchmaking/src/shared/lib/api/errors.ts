/**
 * Typed API error for centralized error handling.
 */
export class ApiError extends Error {
  public readonly status: number;
  public readonly data: unknown;

  constructor(status: number, message: string, data?: unknown) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.data = data;
  }
}

/** Type guard to check if an error is an ApiError. */
export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError;
}
