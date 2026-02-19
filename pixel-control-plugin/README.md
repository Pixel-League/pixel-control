# Pixel Control Plugin

This directory contains the first-party ManiaControl plugin skeleton for Pixel Control.

## Current scaffold

- `src/PixelControlPlugin.php`
  - Implements the full ManiaControl `Plugin` contract (`prepare`, `load`, `unload`, metadata getters).
  - Registers callback groups through a dedicated registry.
  - Wires a local queue + bounded retry policy to an async API client shell.
  - Resolves runtime transport/auth settings from env (`PIXEL_CONTROL_*`) with fallback to ManiaControl settings.
  - Emits monotonic local source sequence IDs (seeded at load) into outbound envelopes.
  - Applies resilient dispatch defaults: bounded queue size, bounded per-callback dispatch batch, and retry-backed requeue on delivery failure.
  - Sends startup registration + periodic heartbeat connectivity envelopes (timer-driven) with capability and runtime context payloads.
  - Normalizes lifecycle callbacks into explicit variants (`warmup.start|end|status`, `match.begin|end`, `map.begin|end`, `round.begin|end`) with shared context metadata.
  - Adds explicit `event_id` in each outbound envelope and keeps idempotency keys derived from stable event identity.
  - Emits clean production logs with `[PixelControl]` prefix for load/unload and transport failures.
  - Supports dev auto-enable when `PIXEL_CONTROL_AUTO_ENABLE=1` is present.
- `src/Callbacks/CallbackRegistry.php`
  - Registers callback shortlist for lifecycle, player, combat, and mode-specific events.
  - Includes ManiaPlanet warmup callbacks in lifecycle coverage.
  - Bridges relevant ManiaPlanet script lifecycle callbacks (`Start/EndMatch`, `Loading/UnloadingMap`, `Start/EndRound`) into the same lifecycle handler.
  - Keeps callback grouping explicit and multi-mode friendly (Elite/Joust/Royal groups are extension points, not hardcoded flow branches).
- `src/Api/`
  - `EventEnvelope.php`: canonical outbound envelope skeleton with explicit `schema_version` + `event_id` fields.
  - `DeliveryError.php`: shared delivery error shape (`code`, `message`, `retryable`, `retry_after_seconds`).
  - `PixelControlApiClientInterface.php`: transport contract.
  - `AsyncPixelControlApiClient.php`: async transport shell with timeout/retry knobs, optional auth headers (`none`, `bearer`, `api_key`), server-ack parsing, and typed delivery result callbacks.
- `src/Queue/`
  - `EventQueueInterface.php`: queue contract for local buffering.
  - `QueueItem.php`: queued envelope model.
  - `InMemoryEventQueue.php`: minimal in-memory strategy used only as a placeholder.
- `src/Retry/`
  - `RetryPolicyInterface.php`: retry policy contract.
  - `ExponentialBackoffRetryPolicy.php`: bounded retry strategy used by default.
  - `NoopRetryPolicy.php`: optional no-retry placeholder strategy.
- `docs/`
  - `event-contract.md`: canonical event naming and contract baseline.
  - `schema/`: JSON schema and machine-readable catalog baseline (`envelope`, `lifecycle payload`, `delivery error`, event-name catalog).

## Next extension points

1. Persist queue items (file-backed or DB-backed) to survive restarts.
2. Extend event-name catalog + schemas when new callbacks/categories are introduced.
3. Stabilize server-side error code catalog + ack status contract to match plugin-side `DeliveryError` parsing.
4. Normalize callback payloads into domain event DTOs before building envelopes.
5. Add callback-specific enrichers for mode metadata (Elite, Siege, Battle, Joust, Royal).
6. Add contract tests against the Pixel Control server ingestion API.

## Delivery error contract (plugin-side)

- Delivery failures are normalized into `DeliveryError` with fields:
  - `code`: machine-readable error identifier,
  - `message`: human-readable explanation,
  - `retryable`: whether retry policy is allowed to requeue,
  - `retry_after_seconds`: optional minimum delay hint.
- Async transport maps failures into shared shapes:
  - transport/exception failures -> retryable errors,
  - encoding/invalid-ack failures -> non-retryable errors,
  - explicit server `error`/`ack` payloads -> parsed into `DeliveryError`.
- Retry semantics:
  - plugin retry policy receives `DeliveryError` (not just a string),
  - non-retryable errors are dropped immediately,
  - retryable errors are requeued with max of policy backoff and `retry_after_seconds`.

## Schema compatibility baseline

- Envelope schema version is currently emitted as `2026-02-19.1`.
- Backward compatibility expectation: plugin should only increment schema version when envelope shape/semantics change.
- Server-side consumers should accept known schema versions and reject unknown ones with explicit error payloads.

Compatibility matrix (initial rollout):

| Plugin version | Envelope schema | Server compatibility expectation | Notes |
| --- | --- | --- | --- |
| `0.1.0-dev` | `2026-02-19.1` | Server must accept `schema_version=2026-02-19.1` envelopes | Initial connectivity + lifecycle normalized baseline |
