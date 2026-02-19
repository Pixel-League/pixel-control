# Pixel Control Server Ingestion Contract (v2026-02-19.1)

This document defines the server-side baseline for at-least-once event ingestion from the Pixel Control plugin.

## Scope and objective

- Endpoint: `POST /plugin/events`
- Goal: guarantee at-least-once delivery semantics from plugin to server while preventing duplicate domain side effects.
- Baseline contract version: `2026-02-19.1`

## Request contract

Expected request body:

```json
{
  "envelope": {
    "event_name": "pixel_control.lifecycle.maniaplanet_beginmatch",
    "schema_version": "2026-02-19.1",
    "event_id": "pc-evt-lifecycle-maniaplanet_beginmatch-1739980000001",
    "event_category": "lifecycle",
    "source_callback": "ManiaPlanet.BeginMatch",
    "source_sequence": 1739980000001,
    "source_time": 1739980000,
    "idempotency_key": "pc-idem-7a6c3048c4b6e4a2df4f59650e2dc71bdffb3e65",
    "payload": {},
    "metadata": {}
  },
  "transport": {
    "attempt": 1,
    "max_attempts": 3,
    "retry_backoff_ms": 250,
    "auth_mode": "api_key"
  }
}
```

Validation rules:

- `envelope` must conform to `pixel-control-plugin/docs/schema/envelope-2026-02-19.1.schema.json`.
- `transport` must conform to `docs/schema/ingestion-request-2026-02-19.1.schema.json`.
- Unknown `schema_version` should be rejected with a typed, non-retryable delivery error.

## Dedupe and idempotency semantics

At-least-once delivery means duplicate requests are expected and valid.

Server dedupe key baseline:

- `dedupe_scope`: stable producer identity resolved from authentication principal (API key owner, token subject, or equivalent).
- `idempotency_key`: `envelope.idempotency_key` from plugin.
- `dedupe_key`: `sha256(dedupe_scope + ":" + idempotency_key)`.

Requirements:

- Server must enforce uniqueness on `dedupe_key`.
- First seen event for a `dedupe_key` executes business side effects exactly once.
- Later duplicates for the same `dedupe_key` must not re-run side effects.
- Duplicates still return an accepted acknowledgment so plugin retries stop.

## Acknowledgment semantics

The plugin currently considers delivery successful when response payload includes:

- `ack.status` in `accepted|ok|success`.

The plugin currently considers delivery failed when response payload includes:

- top-level `error` object, or
- `ack.status` in `rejected|error|failed`.

Server response baseline:

1) First-time accepted event (new receipt)

```json
{
  "ack": {
    "status": "accepted",
    "disposition": "processed",
    "event_id": "pc-evt-lifecycle-maniaplanet_beginmatch-1739980000001",
    "idempotency_key": "pc-idem-7a6c3048c4b6e4a2df4f59650e2dc71bdffb3e65",
    "dedupe_key": "16c9f0f878dc82a2c272f51cc69f3f58f738a2fb6bf4ecf0c2b45a7249d3d4b9",
    "received_at": "2026-02-19T18:14:02Z"
  }
}
```

2) Duplicate accepted event (receipt already exists)

```json
{
  "ack": {
    "status": "accepted",
    "disposition": "duplicate",
    "event_id": "pc-evt-lifecycle-maniaplanet_beginmatch-1739980000001",
    "idempotency_key": "pc-idem-7a6c3048c4b6e4a2df4f59650e2dc71bdffb3e65",
    "dedupe_key": "16c9f0f878dc82a2c272f51cc69f3f58f738a2fb6bf4ecf0c2b45a7249d3d4b9",
    "received_at": "2026-02-19T18:14:05Z"
  }
}
```

3) Rejected event (contract violation, non-retryable)

```json
{
  "ack": {
    "status": "rejected",
    "code": "schema_version_unsupported",
    "message": "schema_version 2026-02-19.2 is not supported",
    "retryable": false,
    "retry_after_seconds": 0
  }
}
```

4) Temporary infrastructure failure (retryable)

```json
{
  "error": {
    "code": "ingestion_unavailable",
    "message": "ingestion storage temporarily unavailable",
    "retryable": true,
    "retry_after_seconds": 5
  }
}
```

## HTTP status baseline

- Return `200` for contract-level outcomes where body is parseable (`accepted` and `rejected` acknowledgments).
- Return `5xx` only for genuine transport/infrastructure outages where server cannot reliably process request.
- If `5xx` includes a body, it should still follow the typed `error` contract whenever possible.

## Processing algorithm baseline

1. Authenticate request and resolve `dedupe_scope`.
2. Parse JSON request body and validate request shape.
3. Validate `envelope` by `schema_version` and envelope schema.
4. Build `dedupe_key` from `dedupe_scope` and `idempotency_key`.
5. Start transaction.
6. Try insert receipt row with unique `dedupe_key`.
7. If inserted:
   - persist raw envelope,
   - run domain side effects,
   - mark receipt as `processed`.
8. If unique conflict:
   - load existing receipt,
   - update duplicate counters/last seen timestamps,
   - skip side effects.
9. Commit and return `ack.status=accepted` with `disposition=processed|duplicate`.

## Storage baseline

Recommended minimum receipt fields:

- `dedupe_key` (unique)
- `dedupe_scope`
- `idempotency_key`
- `event_id`
- `event_name`
- `schema_version`
- `first_seen_at`
- `last_seen_at`
- `duplicate_count`
- `last_transport_attempt`
- `status` (`processed|rejected`)

## Compatibility notes

- This contract is intentionally aligned with plugin-side `DeliveryError` parsing and retry policy behavior.
- Any change to acknowledgment status vocabulary, error shape, or dedupe-key derivation should be treated as a contract change and versioned.
