import { beforeEach, describe, expect, it, vi } from 'vitest';

import { AckResponse } from '../../common/dto/ack-response.dto';
import { EventEnvelopeDto } from '../../common/dto/event-envelope.dto';
import { BatchService } from './batch.service';

const makeEnvelope = (overrides: Partial<EventEnvelopeDto> = {}): EventEnvelopeDto => ({
  event_name: 'pixel_control.lifecycle.sm_begin_map',
  schema_version: '2026-02-20.1',
  event_id: 'pc-evt-lifecycle-sm_begin_map-1',
  event_category: 'lifecycle',
  source_callback: 'SmBeginMap',
  source_sequence: 1,
  source_time: 1740000000,
  idempotency_key: 'pc-idem-lifecycle-001',
  payload: { map: 'TestMap' },
  ...overrides,
});

const makeBatchEnvelope = (events: EventEnvelopeDto[]): EventEnvelopeDto => ({
  event_name: 'pixel_control.batch.flush',
  schema_version: '2026-02-20.1',
  event_id: 'pc-evt-batch-flush-100',
  event_category: 'batch',
  source_callback: 'BatchFlush',
  source_sequence: 100,
  source_time: 1740000000,
  idempotency_key: 'pc-idem-batch-100',
  payload: { events },
});

describe('BatchService', () => {
  let service: BatchService;
  let ingestSingleFn: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    service = new BatchService();
    ingestSingleFn = vi.fn();
  });

  it('processes batch with multiple events and counts correctly', async () => {
    const innerEnvelopes = [
      makeEnvelope({ idempotency_key: 'pc-idem-001' }),
      makeEnvelope({ idempotency_key: 'pc-idem-002' }),
      makeEnvelope({ idempotency_key: 'pc-idem-003' }),
    ];

    ingestSingleFn
      .mockResolvedValueOnce({ ack: { status: 'accepted' } } as AckResponse)
      .mockResolvedValueOnce({
        ack: { status: 'accepted', disposition: 'duplicate' },
      } as AckResponse)
      .mockResolvedValueOnce({ ack: { status: 'accepted' } } as AckResponse);

    const result = await service.ingestBatchEvent(
      makeBatchEnvelope(innerEnvelopes),
      ingestSingleFn,
    );

    expect(result).toEqual({
      ack: {
        status: 'accepted',
        batch_size: 3,
        accepted: 2,
        duplicates: 1,
        rejected: 0,
      },
    });
    expect(ingestSingleFn).toHaveBeenCalledTimes(3);
  });

  it('handles empty batch gracefully', async () => {
    const result = await service.ingestBatchEvent(
      makeBatchEnvelope([]),
      ingestSingleFn,
    );

    expect(result).toEqual({
      ack: {
        status: 'accepted',
        batch_size: 0,
        accepted: 0,
        duplicates: 0,
        rejected: 0,
      },
    });
    expect(ingestSingleFn).not.toHaveBeenCalled();
  });

  it('handles missing events field in payload', async () => {
    const batchEnvelope: EventEnvelopeDto = {
      event_name: 'pixel_control.batch.flush',
      schema_version: '2026-02-20.1',
      event_id: 'pc-evt-batch-flush-101',
      event_category: 'batch',
      source_callback: 'BatchFlush',
      source_sequence: 101,
      source_time: 1740000000,
      idempotency_key: 'pc-idem-batch-101',
      payload: {}, // missing events field
    };

    const result = await service.ingestBatchEvent(batchEnvelope, ingestSingleFn);

    expect(result.ack.batch_size).toBe(0);
    expect(ingestSingleFn).not.toHaveBeenCalled();
  });

  it('counts all duplicates from batch result', async () => {
    const innerEnvelopes = [
      makeEnvelope({ idempotency_key: 'pc-idem-dup-001' }),
      makeEnvelope({ idempotency_key: 'pc-idem-dup-002' }),
    ];

    ingestSingleFn
      .mockResolvedValue({ ack: { status: 'accepted', disposition: 'duplicate' } } as AckResponse);

    const result = await service.ingestBatchEvent(
      makeBatchEnvelope(innerEnvelopes),
      ingestSingleFn,
    );

    expect(result).toEqual({
      ack: {
        status: 'accepted',
        batch_size: 2,
        accepted: 0,
        duplicates: 2,
        rejected: 0,
      },
    });
  });

  it('handles mixed success and failure in batch', async () => {
    const innerEnvelopes = [
      makeEnvelope({ idempotency_key: 'pc-idem-mix-001' }),
      makeEnvelope({ idempotency_key: 'pc-idem-mix-002' }),
      makeEnvelope({ idempotency_key: 'pc-idem-mix-003' }),
    ];

    ingestSingleFn
      .mockResolvedValueOnce({ ack: { status: 'accepted' } } as AckResponse)
      .mockResolvedValueOnce({
        error: { code: 'internal_error', retryable: true, retry_after_seconds: 5 },
      })
      .mockRejectedValueOnce(new Error('Unexpected error'));

    const result = await service.ingestBatchEvent(
      makeBatchEnvelope(innerEnvelopes),
      ingestSingleFn,
    );

    expect(result).toEqual({
      ack: {
        status: 'accepted',
        batch_size: 3,
        accepted: 1,
        duplicates: 0,
        rejected: 2,
      },
    });
  });
});
