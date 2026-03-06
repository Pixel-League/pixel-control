import { ConflictException, NotFoundException } from '@nestjs/common';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ConfigTemplateController } from './config-template.controller';
import type { ConfigTemplateResponse } from './dto/config-template.dto';

// ---------------------------------------------------------------------------
// Factory helpers
// ---------------------------------------------------------------------------

const makeConfig = () => ({
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
});

const makeTemplateResponse = (
  overrides: Partial<ConfigTemplateResponse> = {},
): ConfigTemplateResponse => ({
  id: 'tpl-uuid-1',
  name: 'Elite Standard',
  description: 'Default config',
  config: makeConfig(),
  server_count: 0,
  created_at: '2026-03-06T00:00:00.000Z',
  updated_at: '2026-03-06T00:00:00.000Z',
  ...overrides,
});

const makeServiceStub = () => ({
  create: vi.fn().mockResolvedValue(makeTemplateResponse()),
  findAll: vi.fn().mockResolvedValue([makeTemplateResponse()]),
  findOne: vi.fn().mockResolvedValue(makeTemplateResponse()),
  update: vi.fn().mockResolvedValue(makeTemplateResponse({ name: 'Updated Name' })),
  remove: vi.fn().mockResolvedValue({ deleted: true }),
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('ConfigTemplateController', () => {
  let controller: ConfigTemplateController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new ConfigTemplateController(service as never);
  });

  // ─── create ─────────────────────────────────────────────────────────────

  describe('create', () => {
    it('calls service.create with the DTO', async () => {
      const dto = { name: 'Elite Standard', config: makeConfig() };
      await controller.create(dto);
      expect(service.create).toHaveBeenCalledWith(dto);
    });

    it('returns the created template response', async () => {
      const result = await controller.create({ name: 'Elite Standard', config: makeConfig() });
      expect(result.id).toBe('tpl-uuid-1');
      expect(result.name).toBe('Elite Standard');
      expect(result.server_count).toBe(0);
    });

    it('propagates ConflictException for duplicate name', async () => {
      service.create.mockRejectedValue(new ConflictException('name in use'));
      await expect(
        controller.create({ name: 'Duplicate', config: makeConfig() }),
      ).rejects.toThrow(ConflictException);
    });
  });

  // ─── findAll ────────────────────────────────────────────────────────────

  describe('findAll', () => {
    it('returns an array of templates', async () => {
      const result = await controller.findAll();
      expect(Array.isArray(result)).toBe(true);
      expect(result).toHaveLength(1);
    });

    it('returns empty array when no templates exist', async () => {
      service.findAll.mockResolvedValue([]);
      const result = await controller.findAll();
      expect(result).toHaveLength(0);
    });
  });

  // ─── findOne ────────────────────────────────────────────────────────────

  describe('findOne', () => {
    it('calls service.findOne with the id', async () => {
      await controller.findOne('tpl-uuid-1');
      expect(service.findOne).toHaveBeenCalledWith('tpl-uuid-1');
    });

    it('returns the template response', async () => {
      const result = await controller.findOne('tpl-uuid-1');
      expect(result.id).toBe('tpl-uuid-1');
    });

    it('propagates NotFoundException for nonexistent template', async () => {
      service.findOne.mockRejectedValue(new NotFoundException('not found'));
      await expect(controller.findOne('nonexistent')).rejects.toThrow(NotFoundException);
    });
  });

  // ─── update ─────────────────────────────────────────────────────────────

  describe('update', () => {
    it('calls service.update with id and DTO', async () => {
      const dto = { name: 'Updated Name' };
      await controller.update('tpl-uuid-1', dto);
      expect(service.update).toHaveBeenCalledWith('tpl-uuid-1', dto);
    });

    it('returns the updated template response', async () => {
      const result = await controller.update('tpl-uuid-1', { name: 'Updated Name' });
      expect(result.name).toBe('Updated Name');
    });

    it('propagates NotFoundException for nonexistent template', async () => {
      service.update.mockRejectedValue(new NotFoundException('not found'));
      await expect(
        controller.update('nonexistent', { name: 'New' }),
      ).rejects.toThrow(NotFoundException);
    });

    it('propagates ConflictException for duplicate name on update', async () => {
      service.update.mockRejectedValue(new ConflictException('name in use'));
      await expect(
        controller.update('tpl-uuid-1', { name: 'Taken' }),
      ).rejects.toThrow(ConflictException);
    });
  });

  // ─── remove ─────────────────────────────────────────────────────────────

  describe('remove', () => {
    it('calls service.remove with the id', async () => {
      await controller.remove('tpl-uuid-1');
      expect(service.remove).toHaveBeenCalledWith('tpl-uuid-1');
    });

    it('returns deleted: true on success', async () => {
      const result = await controller.remove('tpl-uuid-1');
      expect(result.deleted).toBe(true);
    });

    it('propagates NotFoundException for nonexistent template', async () => {
      service.remove.mockRejectedValue(new NotFoundException('not found'));
      await expect(controller.remove('nonexistent')).rejects.toThrow(NotFoundException);
    });

    it('propagates ConflictException when servers are linked', async () => {
      service.remove.mockRejectedValue(new ConflictException('servers linked'));
      await expect(controller.remove('tpl-uuid-1')).rejects.toThrow(ConflictException);
    });
  });
});
