import { describe, it, expect } from 'vitest';
import { ApiError, isApiError } from './errors';

describe('ApiError', () => {
  it('creates an error with status and message', () => {
    const error = new ApiError(404, 'Not Found');
    expect(error.status).toBe(404);
    expect(error.message).toBe('Not Found');
    expect(error.data).toBeUndefined();
    expect(error.name).toBe('ApiError');
  });

  it('creates an error with data', () => {
    const data = { details: 'some info' };
    const error = new ApiError(400, 'Bad Request', data);
    expect(error.data).toEqual(data);
  });

  it('is an instance of Error', () => {
    const error = new ApiError(500, 'Server Error');
    expect(error).toBeInstanceOf(Error);
  });
});

describe('isApiError', () => {
  it('returns true for ApiError instances', () => {
    const error = new ApiError(404, 'Not Found');
    expect(isApiError(error)).toBe(true);
  });

  it('returns false for regular errors', () => {
    const error = new Error('Something went wrong');
    expect(isApiError(error)).toBe(false);
  });

  it('returns false for null', () => {
    expect(isApiError(null)).toBe(false);
  });

  it('returns false for non-error objects', () => {
    expect(isApiError({ status: 404, message: 'Not Found' })).toBe(false);
  });
});
