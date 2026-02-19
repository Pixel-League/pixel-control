# Pixel Control Event Contract (v2026-02-19.1)

This document defines the canonical naming and schema baseline for plugin-to-server envelope delivery.

## Naming rules

- Event name pattern: `pixel_control.<event_category>.<normalized_source_callback>`.
- `event_category` values: `connectivity`, `lifecycle`, `player`, `combat`, `mode`.
- `normalized_source_callback` is lowercase with non-alphanumeric separators converted to `_`.
- `event_id` pattern: `pc-evt-<event_category>-<normalized_source_callback>-<source_sequence>`.
- `idempotency_key` pattern: `pc-idem-<sha1(event_id)>`.

## Canonical event catalog (baseline)

- Machine-readable catalog: `docs/schema/event-name-catalog-2026-02-19.1.json`.
- Baseline catalog currently contains `41` canonical event names:
  - connectivity: `2`
  - lifecycle: `21`
  - player: `4`
  - combat: `6`
  - mode: `8`
- Any event-name addition/removal or rename must update both:
  - the event-name catalog file,
  - the envelope schema baseline,
  - and should trigger a `schema_version` bump when contract semantics change.

## Lifecycle variant catalog

Lifecycle payload field `variant` and metadata field `lifecycle_variant` are normalized to:

- `warmup.start`
- `warmup.end`
- `warmup.status`
- `match.begin`
- `match.end`
- `map.begin`
- `map.end`
- `round.begin`
- `round.end`
- `lifecycle.unknown`

Lifecycle payload field `source_channel` values:

- `maniaplanet` for direct callback-manager lifecycle callbacks.
- `script` for script lifecycle callbacks bridged via `registerScriptCallbackListener(...)`.

## JSON schema baseline

- Envelope schema: `docs/schema/envelope-2026-02-19.1.schema.json`.
- Lifecycle payload schema: `docs/schema/lifecycle-payload-2026-02-19.1.schema.json`.
- Delivery error schema: `docs/schema/delivery-error-2026-02-19.1.schema.json`.

Validation expectation:

- Plugin emitters must produce envelopes valid against the baseline schema files.
- Server ingestors should validate incoming envelopes by `schema_version` and reject unknown/invalid contracts with a typed delivery error response.

## Compatibility baseline

- Plugin `0.1.0-dev` emits `schema_version=2026-02-19.1`.
- Servers consuming this baseline must support:
  - canonical event naming rules,
  - envelope metadata fields (`event_id`, `schema_version`, source fields),
  - lifecycle variant catalog and source channel semantics,
  - typed delivery error contract for retry behavior (`code`, `message`, `retryable`, `retry_after_seconds`).
