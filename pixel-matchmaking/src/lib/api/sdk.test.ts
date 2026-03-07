import { describe, it, expect } from 'vitest';
import { existsSync } from 'fs';
import { resolve } from 'path';
import { PixelControlApi, HealthService, LifecycleService } from './generated';

describe('SDK generation', () => {
  const generatedDir = resolve(__dirname, 'generated');

  it('generation script exists', () => {
    const scriptPath = resolve(__dirname, '../../../scripts/generate-sdk.sh');
    expect(existsSync(scriptPath)).toBe(true);
  });

  it('generated SDK directory exists', () => {
    expect(existsSync(generatedDir)).toBe(true);
  });

  it('swagger.json is present', () => {
    expect(existsSync(resolve(generatedDir, 'swagger.json'))).toBe(true);
  });

  it('generated index.ts barrel export exists', () => {
    expect(existsSync(resolve(generatedDir, 'index.ts'))).toBe(true);
  });

  it('exports PixelControlApi class', () => {
    expect(PixelControlApi).toBeDefined();
    expect(typeof PixelControlApi).toBe('function');
  });

  it('exports generated service classes', () => {
    expect(HealthService).toBeDefined();
    expect(LifecycleService).toBeDefined();
  });

  it('PixelControlApi can be instantiated with config', () => {
    const client = new PixelControlApi({ BASE: 'http://localhost:3000/v1' });
    expect(client).toBeDefined();
    expect(client.health).toBeDefined();
    expect(client.lifecycle).toBeDefined();
  });
});
