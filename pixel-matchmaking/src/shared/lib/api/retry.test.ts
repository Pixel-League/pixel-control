import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fetchWithRetry } from './retry';

describe('fetchWithRetry', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it('returns successful response on first attempt', async () => {
    const mockResponse = new Response('OK', { status: 200 });
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(mockResponse);

    const result = await fetchWithRetry('http://test.com/api');
    expect(result.status).toBe(200);
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it('retries on 500 for GET requests', async () => {
    const errorResponse = new Response('Error', { status: 500 });
    const okResponse = new Response('OK', { status: 200 });

    vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(errorResponse)
      .mockResolvedValueOnce(okResponse);

    const result = await fetchWithRetry('http://test.com/api', { method: 'GET' });
    expect(result.status).toBe(200);
    expect(fetch).toHaveBeenCalledTimes(2);
  });

  it('does NOT retry POST requests on 500', async () => {
    const errorResponse = new Response('Error', { status: 500 });
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(errorResponse);

    const result = await fetchWithRetry('http://test.com/api', { method: 'POST' });
    expect(result.status).toBe(500);
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it('retries on network errors for GET requests', async () => {
    const networkError = new Error('Network error');
    const okResponse = new Response('OK', { status: 200 });

    vi.spyOn(globalThis, 'fetch')
      .mockRejectedValueOnce(networkError)
      .mockResolvedValueOnce(okResponse);

    const result = await fetchWithRetry('http://test.com/api');
    expect(result.status).toBe(200);
    expect(fetch).toHaveBeenCalledTimes(2);
  });

  it('throws after max retries', async () => {
    const networkError = new Error('Network error');
    vi.spyOn(globalThis, 'fetch').mockRejectedValue(networkError);

    await expect(fetchWithRetry('http://test.com/api')).rejects.toThrow('Network error');
    expect(fetch).toHaveBeenCalledTimes(4); // 1 initial + 3 retries
  });

  it('does NOT retry POST on network error', async () => {
    const networkError = new Error('Network error');
    vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(networkError);

    await expect(
      fetchWithRetry('http://test.com/api', { method: 'POST' }),
    ).rejects.toThrow('Network error');
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it('retries PUT requests (idempotent)', async () => {
    const errorResponse = new Response('Error', { status: 503 });
    const okResponse = new Response('OK', { status: 200 });

    vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(errorResponse)
      .mockResolvedValueOnce(okResponse);

    const result = await fetchWithRetry('http://test.com/api', { method: 'PUT' });
    expect(result.status).toBe(200);
    expect(fetch).toHaveBeenCalledTimes(2);
  });

  it('retries DELETE requests (idempotent)', async () => {
    const errorResponse = new Response('Error', { status: 502 });
    const okResponse = new Response('OK', { status: 200 });

    vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(errorResponse)
      .mockResolvedValueOnce(okResponse);

    const result = await fetchWithRetry('http://test.com/api', { method: 'DELETE' });
    expect(result.status).toBe(200);
    expect(fetch).toHaveBeenCalledTimes(2);
  });

  it('does not retry 4xx errors', async () => {
    const errorResponse = new Response('Bad Request', { status: 400 });
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(errorResponse);

    const result = await fetchWithRetry('http://test.com/api');
    expect(result.status).toBe(400);
    expect(fetch).toHaveBeenCalledTimes(1);
  });
});
