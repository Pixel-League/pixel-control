import { describe, expect, it } from 'vitest';

import { ManiaControlSocketClient } from './maniacontrol-socket.client';

// ---------------------------------------------------------------------------
// Test the encryption/decryption round-trip without a real socket.
// We access private methods via (client as any) for testing internals.
// ---------------------------------------------------------------------------

describe('ManiaControlSocketClient', () => {
  let client: ManiaControlSocketClient;

  beforeEach(() => {
    client = new ManiaControlSocketClient();
  });

  describe('encrypt / decrypt round-trip', () => {
    it('decrypts a value that was encrypted with the same password', () => {
      const password = 'test-password-123';
      const plaintext = JSON.stringify({ method: 'test', data: { foo: 'bar' } });
      const encrypted = (client as unknown as Record<string, (p: string, pw: string) => string>)['encrypt'](plaintext, password);
      const decrypted = (client as unknown as Record<string, (c: string, pw: string) => string>)['decrypt'](encrypted, password);
      expect(decrypted).toBe(plaintext);
    });

    it('produces different ciphertext for different passwords', () => {
      const plaintext = 'hello world';
      const enc1 = (client as unknown as Record<string, (p: string, pw: string) => string>)['encrypt'](plaintext, 'password1');
      const enc2 = (client as unknown as Record<string, (p: string, pw: string) => string>)['encrypt'](plaintext, 'password2');
      expect(enc1).not.toBe(enc2);
    });

    it('produces base64 output from encrypt', () => {
      const encrypted = (client as unknown as Record<string, (p: string, pw: string) => string>)['encrypt']('test', 'password');
      expect(typeof encrypted).toBe('string');
      // Base64 characters only.
      expect(/^[A-Za-z0-9+/=]+$/.test(encrypted)).toBe(true);
    });

    it('handles an empty password (padded to 24 null bytes)', () => {
      const plaintext = 'test message';
      const encrypted = (client as unknown as Record<string, (p: string, pw: string) => string>)['encrypt'](plaintext, '');
      const decrypted = (client as unknown as Record<string, (c: string, pw: string) => string>)['decrypt'](encrypted, '');
      expect(decrypted).toBe(plaintext);
    });

    it('handles a password longer than 24 characters (truncated)', () => {
      const longPassword = 'this-is-a-very-long-password-123456789';
      const plaintext = 'data payload';
      const encrypted = (client as unknown as Record<string, (p: string, pw: string) => string>)['encrypt'](plaintext, longPassword);
      const decrypted = (client as unknown as Record<string, (c: string, pw: string) => string>)['decrypt'](encrypted, longPassword);
      expect(decrypted).toBe(plaintext);
    });

    it('handles complex JSON payloads', () => {
      const password = 'socket-password';
      const payload = JSON.stringify({
        method: 'PixelControl.Admin.ExecuteAction',
        data: {
          action: 'map.skip',
          server_login: 'pixel-elite-1.server.local',
          auth: { mode: 'link_bearer', token: 'abc123' },
        },
      });
      const encrypted = (client as unknown as Record<string, (p: string, pw: string) => string>)['encrypt'](payload, password);
      const decrypted = (client as unknown as Record<string, (c: string, pw: string) => string>)['decrypt'](encrypted, password);
      expect(decrypted).toBe(payload);
    });
  });

  describe('sendCommand - connection failure handling', () => {
    it('resolves with error=true when connection is refused', async () => {
      // Port 1 is virtually always closed/refused.
      const result = await client.sendCommand('127.0.0.1', 1, 'password', 'Test.Method', {});
      expect(result.error).toBe(true);
      const data = result.data as Record<string, unknown>;
      expect(typeof data['message']).toBe('string');
    }, 15_000);

    it('resolves with error=true when host is unreachable (timeout)', async () => {
      // 192.0.2.1 is TEST-NET per RFC 5737 — always unreachable, causes timeout.
      const result = await client.sendCommand('192.0.2.1', 31501, 'password', 'Test.Method', {});
      expect(result.error).toBe(true);
    }, 15_000);
  });
});
