import { Injectable, Logger } from '@nestjs/common';
import * as crypto from 'crypto';
import * as net from 'net';

// ManiaControl CommunicationManager socket protocol:
// AES-192-CBC encrypted TCP with `<length>\n<encrypted_payload>` framing.
// IV is the constant 'kZ2Kt0CzKUjN2MJX' (16 bytes, matches PHP openssl_encrypt).

const ENCRYPTION_METHOD = 'aes-192-cbc';
const ENCRYPTION_IV = 'kZ2Kt0CzKUjN2MJX';
const CONNECTION_TIMEOUT_MS = 5_000;
const READ_TIMEOUT_MS = 10_000;

export interface SocketCommandResult {
  error: boolean;
  data: unknown;
}

/**
 * Low-level service for communicating with the ManiaControl CommunicationManager socket.
 * Handles TCP connection lifecycle, AES-192-CBC encryption/decryption, and request framing.
 */
@Injectable()
export class ManiaControlSocketClient {
  private readonly logger = new Logger(ManiaControlSocketClient.name);

  /**
   * Encrypts a plaintext string using AES-192-CBC with the socket password as key.
   */
  private encrypt(plaintext: string, password: string): string {
    // Key must be exactly 24 bytes for AES-192. Pad or truncate.
    const key = Buffer.alloc(24, 0);
    Buffer.from(password, 'utf8').copy(key, 0, 0, Math.min(password.length, 24));
    const iv = Buffer.from(ENCRYPTION_IV, 'utf8');
    const cipher = crypto.createCipheriv(ENCRYPTION_METHOD, key, iv);
    const encrypted = Buffer.concat([cipher.update(plaintext, 'utf8'), cipher.final()]);
    return encrypted.toString('base64');
  }

  /**
   * Decrypts a base64-encoded AES-192-CBC ciphertext using the socket password as key.
   */
  private decrypt(ciphertext: string, password: string): string {
    const key = Buffer.alloc(24, 0);
    Buffer.from(password, 'utf8').copy(key, 0, 0, Math.min(password.length, 24));
    const iv = Buffer.from(ENCRYPTION_IV, 'utf8');
    const decipher = crypto.createDecipheriv(ENCRYPTION_METHOD, key, iv);
    const decrypted = Buffer.concat([
      decipher.update(Buffer.from(ciphertext, 'base64')),
      decipher.final(),
    ]);
    return decrypted.toString('utf8');
  }

  /**
   * Sends a command to the ManiaControl CommunicationManager socket and returns the response.
   *
   * Protocol: send `<length>\n<encrypted_data>`, receive `<length>\n<encrypted_response>`.
   * Each new call opens a fresh TCP connection and closes it after receiving the response.
   */
  async sendCommand(
    host: string,
    port: number,
    password: string,
    method: string,
    data: Record<string, unknown>,
  ): Promise<SocketCommandResult> {
    const payload = JSON.stringify({ method, data });
    const encrypted = this.encrypt(payload, password);
    const frame = `${encrypted.length}\n${encrypted}`;

    return new Promise<SocketCommandResult>((resolve) => {
      let settled = false;
      let buffer = '';
      let connectTimer: ReturnType<typeof setTimeout> | null = null;
      let readTimer: ReturnType<typeof setTimeout> | null = null;

      const settle = (result: SocketCommandResult) => {
        if (settled) return;
        settled = true;
        if (connectTimer) clearTimeout(connectTimer);
        if (readTimer) clearTimeout(readTimer);
        socket.destroy();
        resolve(result);
      };

      const socket = new net.Socket();

      connectTimer = setTimeout(() => {
        this.logger.warn(`Socket connection timeout to ${host}:${port}`);
        settle({ error: true, data: { code: 'socket_timeout', message: 'Connection timeout' } });
      }, CONNECTION_TIMEOUT_MS);

      socket.on('error', (err) => {
        this.logger.warn(`Socket error connecting to ${host}:${port}: ${err.message}`);
        settle({ error: true, data: { code: 'socket_error', message: err.message } });
      });

      socket.connect(port, host, () => {
        if (connectTimer) {
          clearTimeout(connectTimer);
          connectTimer = null;
        }

        // Start read timeout once connected.
        readTimer = setTimeout(() => {
          this.logger.warn(`Socket read timeout from ${host}:${port}`);
          settle({ error: true, data: { code: 'socket_read_timeout', message: 'Read timeout' } });
        }, READ_TIMEOUT_MS);

        socket.write(frame);
      });

      socket.on('data', (chunk: Buffer) => {
        buffer += chunk.toString('utf8');
        // Check if we have a complete frame: <length>\n<data>
        const newlineIdx = buffer.indexOf('\n');
        if (newlineIdx === -1) return;

        const lengthStr = buffer.substring(0, newlineIdx);
        const expectedLength = parseInt(lengthStr, 10);
        if (isNaN(expectedLength)) {
          settle({ error: true, data: { code: 'protocol_error', message: 'Invalid length prefix' } });
          return;
        }

        const payload = buffer.substring(newlineIdx + 1);
        if (payload.length < expectedLength) return; // Wait for more data.

        const encryptedResponse = payload.substring(0, expectedLength);
        let responseText: string;
        try {
          responseText = this.decrypt(encryptedResponse, password);
        } catch (decryptErr) {
          settle({
            error: true,
            data: { code: 'decrypt_error', message: String(decryptErr) },
          });
          return;
        }

        let parsed: unknown;
        try {
          parsed = JSON.parse(responseText);
        } catch (parseErr) {
          settle({
            error: true,
            data: { code: 'parse_error', message: String(parseErr) },
          });
          return;
        }

        settle({ error: false, data: parsed });
      });
    });
  }
}
