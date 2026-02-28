import {
  BadRequestException,
  InternalServerErrorException,
} from '@nestjs/common';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { EventEnvelopeDto } from '../common/dto/event-envelope.dto';
import { IngestionController } from './ingestion.controller';
import { IngestionService } from './ingestion.service';

const makeEnvelopeFields = (category = 'connectivity') => ({
  event_name: `pixel_control.${category}.plugin_registration`,
  schema_version: '2026-02-20.1',
  event_id: `pc-evt-${category}-plugin_registration-1`,
  event_category: category,
  source_callback: 'PixelControl.PluginRegistration',
  source_sequence: 1,
  source_time: 1740000000,
  idempotency_key: `pc-idem-${category}-001`,
  payload: { type: 'plugin_registration' },
});

const makeFlatEnvelope = (category = 'connectivity'): EventEnvelopeDto =>
  makeEnvelopeFields(category) as EventEnvelopeDto;

const makeWrappedBody = (category = 'connectivity') => ({
  envelope: makeEnvelopeFields(category),
  transport: { attempt: 1, sent_at: 1740000000 },
});

const makeServiceStub = () => ({
  ingestEvent: vi.fn(),
});

describe('IngestionController', () => {
  let controller: IngestionController;
  let service: ReturnType<typeof makeServiceStub>;

  beforeEach(() => {
    service = makeServiceStub();
    controller = new IngestionController(
      service as unknown as IngestionService,
    );
  });

  describe('Flat envelope format (backward-compatible curl format)', () => {
    it('returns accepted ack for valid flat event with server-login header', async () => {
      service.ingestEvent.mockResolvedValue({ ack: { status: 'accepted' } });

      const result = await controller.ingestEvent(
        'test-server',
        '1.0.0',
        makeFlatEnvelope(),
      );

      expect(result).toEqual({ ack: { status: 'accepted' } });
      expect(service.ingestEvent).toHaveBeenCalledWith(
        'test-server',
        '1.0.0',
        expect.objectContaining({
          event_name: 'pixel_control.connectivity.plugin_registration',
        }),
      );
    });

    it('returns duplicate ack when service returns disposition=duplicate', async () => {
      service.ingestEvent.mockResolvedValue({
        ack: { status: 'accepted', disposition: 'duplicate' },
      });

      const result = await controller.ingestEvent(
        'test-server',
        undefined,
        makeFlatEnvelope(),
      );

      expect(result).toEqual({
        ack: { status: 'accepted', disposition: 'duplicate' },
      });
    });
  });

  describe('Wrapped envelope format (plugin transport wrapper)', () => {
    it('returns accepted ack for wrapped payload format { envelope: {...}, transport: {...} }', async () => {
      service.ingestEvent.mockResolvedValue({ ack: { status: 'accepted' } });

      const result = await controller.ingestEvent(
        'test-server',
        '1.0.0',
        makeWrappedBody(),
      );

      expect(result).toEqual({ ack: { status: 'accepted' } });
      expect(service.ingestEvent).toHaveBeenCalledWith(
        'test-server',
        '1.0.0',
        expect.objectContaining({
          event_name: 'pixel_control.connectivity.plugin_registration',
        }),
      );
    });

    it('returns duplicate ack for wrapped payload when service returns disposition=duplicate', async () => {
      service.ingestEvent.mockResolvedValue({
        ack: { status: 'accepted', disposition: 'duplicate' },
      });

      const result = await controller.ingestEvent(
        'test-server',
        undefined,
        makeWrappedBody(),
      );

      expect(result).toEqual({
        ack: { status: 'accepted', disposition: 'duplicate' },
      });
    });
  });

  describe('Category routing', () => {
    const categories = ['lifecycle', 'combat', 'player', 'mode'];

    for (const category of categories) {
      it(`routes ${category} category events correctly`, async () => {
        service.ingestEvent.mockResolvedValue({ ack: { status: 'accepted' } });

        const result = await controller.ingestEvent(
          'test-server',
          '1.0.0',
          makeFlatEnvelope(category),
        );

        expect(result).toEqual({ ack: { status: 'accepted' } });
        expect(service.ingestEvent).toHaveBeenCalledWith(
          'test-server',
          '1.0.0',
          expect.objectContaining({ event_category: category }),
        );
      });
    }

    it('passes unknown category to service (service handles gracefully)', async () => {
      service.ingestEvent.mockResolvedValue({ ack: { status: 'accepted' } });

      const result = await controller.ingestEvent(
        'test-server',
        '1.0.0',
        makeFlatEnvelope('unknown_category'),
      );

      expect(result).toEqual({ ack: { status: 'accepted' } });
      expect(service.ingestEvent).toHaveBeenCalledWith(
        'test-server',
        '1.0.0',
        expect.objectContaining({ event_category: 'unknown_category' }),
      );
    });
  });

  describe('Batch ack response', () => {
    it('returns batch ack for batch events', async () => {
      const batchAck = {
        ack: {
          status: 'accepted',
          batch_size: 3,
          accepted: 2,
          duplicates: 1,
          rejected: 0,
        },
      };
      service.ingestEvent.mockResolvedValue(batchAck);

      const result = await controller.ingestEvent(
        'test-server',
        '1.0.0',
        makeFlatEnvelope('batch'),
      );

      expect(result).toEqual(batchAck);
    });
  });

  describe('Error cases', () => {
    it('throws BadRequestException when X-Pixel-Server-Login header is missing', async () => {
      await expect(
        controller.ingestEvent(undefined, undefined, makeFlatEnvelope()),
      ).rejects.toThrow(BadRequestException);
    });

    it('throws BadRequestException when wrapped body has invalid envelope', async () => {
      await expect(
        controller.ingestEvent('test-server', undefined, {
          envelope: { invalid: 'data' },
          transport: {},
        }),
      ).rejects.toThrow(BadRequestException);
    });

    it('throws BadRequestException when flat body is missing required fields', async () => {
      await expect(
        controller.ingestEvent('test-server', undefined, {
          some_unknown_field: 'value',
        }),
      ).rejects.toThrow(BadRequestException);
    });

    it('throws InternalServerErrorException when service returns error response', async () => {
      service.ingestEvent.mockResolvedValue({
        error: {
          code: 'internal_error',
          retryable: true,
          retry_after_seconds: 5,
        },
      });

      await expect(
        controller.ingestEvent('test-server', undefined, makeFlatEnvelope()),
      ).rejects.toThrow(InternalServerErrorException);
    });
  });
});
