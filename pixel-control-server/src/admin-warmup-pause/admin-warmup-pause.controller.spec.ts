import { beforeEach, describe, expect, it, vi } from 'vitest';

import { AdminWarmupPauseController } from './admin-warmup-pause.controller';

// ---------------------------------------------------------------------------
// Factory helpers
// ---------------------------------------------------------------------------

const makeActionResponse = (actionName: string, code: string) => ({
  action_name: actionName,
  success: true,
  code,
  message: 'OK',
  details: undefined,
});

const makeServiceStub = () => ({
  extendWarmup: vi.fn().mockResolvedValue(makeActionResponse('warmup.extend', 'warmup_extended')),
  endWarmup: vi.fn().mockResolvedValue(makeActionResponse('warmup.end', 'warmup_ended')),
  startPause: vi.fn().mockResolvedValue(makeActionResponse('pause.start', 'pause_started')),
  endPause: vi.fn().mockResolvedValue(makeActionResponse('pause.end', 'pause_ended')),
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('AdminWarmupPauseController', () => {
  let controller: AdminWarmupPauseController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new AdminWarmupPauseController(service as never);
  });

  describe('extendWarmup', () => {
    it('calls service.extendWarmup with serverLogin and seconds', async () => {
      await controller.extendWarmup('test-server', { seconds: 30 });
      expect(service.extendWarmup).toHaveBeenCalledWith('test-server', 30);
    });

    it('returns the service response', async () => {
      const result = await controller.extendWarmup('test-server', { seconds: 30 });
      expect(result.action_name).toBe('warmup.extend');
      expect(result.success).toBe(true);
    });
  });

  describe('endWarmup', () => {
    it('calls service.endWarmup with the serverLogin', async () => {
      await controller.endWarmup('test-server');
      expect(service.endWarmup).toHaveBeenCalledWith('test-server');
    });

    it('returns the service response', async () => {
      const result = await controller.endWarmup('test-server');
      expect(result.action_name).toBe('warmup.end');
    });
  });

  describe('startPause', () => {
    it('calls service.startPause with the serverLogin', async () => {
      await controller.startPause('test-server');
      expect(service.startPause).toHaveBeenCalledWith('test-server');
    });

    it('returns the service response', async () => {
      const result = await controller.startPause('test-server');
      expect(result.action_name).toBe('pause.start');
    });
  });

  describe('endPause', () => {
    it('calls service.endPause with the serverLogin', async () => {
      await controller.endPause('test-server');
      expect(service.endPause).toHaveBeenCalledWith('test-server');
    });

    it('returns the service response', async () => {
      const result = await controller.endPause('test-server');
      expect(result.action_name).toBe('pause.end');
      expect(result.code).toBe('pause_ended');
    });
  });
});
