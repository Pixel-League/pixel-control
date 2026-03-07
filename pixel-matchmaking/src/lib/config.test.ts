import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

describe('config', () => {
  const originalEnv = process.env;

  beforeEach(() => {
    vi.resetModules();
    process.env = { ...originalEnv };
  });

  afterEach(() => {
    process.env = originalEnv;
  });

  it('uses default API URL when env var is not set', async () => {
    delete process.env.NEXT_PUBLIC_API_URL;
    const { config } = await import('./config');
    expect(config.apiUrl).toBe('http://localhost:3000/v1');
  });

  it('reads NEXT_PUBLIC_API_URL when set', async () => {
    process.env.NEXT_PUBLIC_API_URL = 'https://api.example.com/v1';
    const { config } = await import('./config');
    expect(config.apiUrl).toBe('https://api.example.com/v1');
  });
});
