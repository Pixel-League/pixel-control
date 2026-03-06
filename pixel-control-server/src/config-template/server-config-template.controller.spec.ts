import { NotFoundException } from '@nestjs/common';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ServerConfigTemplateController } from './server-config-template.controller';
import type { ConfigTemplateResponse } from './dto/config-template.dto';

// ---------------------------------------------------------------------------
// Factory helpers
// ---------------------------------------------------------------------------

const makeTemplateResponse = (
  overrides: Partial<ConfigTemplateResponse> = {},
): ConfigTemplateResponse => ({
  id: 'tpl-uuid-1',
  name: 'Elite Standard',
  description: 'Default config',
  config: {
    current_best_of: 3,
    team_maps_score: { team_a: 0, team_b: 0 },
    team_round_score: { team_a: 0, team_b: 0 },
    team_policy_enabled: false,
    team_switch_lock: false,
    team_roster: {},
    whitelist_enabled: false,
    whitelist: [],
    vote_policy: 'default',
    vote_ratios: {},
  },
  server_count: 0,
  created_at: '2026-03-06T00:00:00.000Z',
  updated_at: '2026-03-06T00:00:00.000Z',
  ...overrides,
});

const makeServiceStub = () => ({
  linkServerToTemplate: vi.fn().mockResolvedValue({
    linked: true,
    template_id: 'tpl-uuid-1',
    template_name: 'Elite Standard',
  }),
  unlinkServer: vi.fn().mockResolvedValue({ unlinked: true }),
  getServerTemplate: vi.fn().mockResolvedValue({ template: makeTemplateResponse() }),
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('ServerConfigTemplateController', () => {
  let controller: ServerConfigTemplateController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new ServerConfigTemplateController(service as never);
  });

  // ─── linkServerToTemplate ───────────────────────────────────────────────

  describe('linkServerToTemplate', () => {
    it('calls service.linkServerToTemplate with serverLogin and template_id', async () => {
      await controller.linkServerToTemplate('test-server', { template_id: 'tpl-uuid-1' });
      expect(service.linkServerToTemplate).toHaveBeenCalledWith('test-server', 'tpl-uuid-1');
    });

    it('returns linked response', async () => {
      const result = await controller.linkServerToTemplate('test-server', { template_id: 'tpl-uuid-1' });
      expect(result.linked).toBe(true);
      expect(result.template_id).toBe('tpl-uuid-1');
      expect(result.template_name).toBe('Elite Standard');
    });

    it('propagates NotFoundException for nonexistent server', async () => {
      service.linkServerToTemplate.mockRejectedValue(new NotFoundException('Server not found'));
      await expect(
        controller.linkServerToTemplate('nonexistent', { template_id: 'tpl-uuid-1' }),
      ).rejects.toThrow(NotFoundException);
    });

    it('propagates NotFoundException for nonexistent template', async () => {
      service.linkServerToTemplate.mockRejectedValue(new NotFoundException('Template not found'));
      await expect(
        controller.linkServerToTemplate('test-server', { template_id: 'nonexistent' }),
      ).rejects.toThrow(NotFoundException);
    });
  });

  // ─── unlinkServer ──────────────────────────────────────────────────────

  describe('unlinkServer', () => {
    it('calls service.unlinkServer with serverLogin', async () => {
      await controller.unlinkServer('test-server');
      expect(service.unlinkServer).toHaveBeenCalledWith('test-server');
    });

    it('returns unlinked response', async () => {
      const result = await controller.unlinkServer('test-server');
      expect(result.unlinked).toBe(true);
    });

    it('propagates NotFoundException for nonexistent server', async () => {
      service.unlinkServer.mockRejectedValue(new NotFoundException('Server not found'));
      await expect(controller.unlinkServer('nonexistent')).rejects.toThrow(NotFoundException);
    });
  });

  // ─── getServerTemplate ─────────────────────────────────────────────────

  describe('getServerTemplate', () => {
    it('calls service.getServerTemplate with serverLogin', async () => {
      await controller.getServerTemplate('test-server');
      expect(service.getServerTemplate).toHaveBeenCalledWith('test-server');
    });

    it('returns template when linked', async () => {
      const result = await controller.getServerTemplate('test-server');
      expect(result.template).not.toBeNull();
      expect(result.template!.id).toBe('tpl-uuid-1');
    });

    it('returns null template when not linked', async () => {
      service.getServerTemplate.mockResolvedValue({ template: null });
      const result = await controller.getServerTemplate('test-server');
      expect(result.template).toBeNull();
    });

    it('propagates NotFoundException for nonexistent server', async () => {
      service.getServerTemplate.mockRejectedValue(new NotFoundException('Server not found'));
      await expect(controller.getServerTemplate('nonexistent')).rejects.toThrow(NotFoundException);
    });
  });
});
