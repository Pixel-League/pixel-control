# HANDOFF - Autonomous Wave 5 (2026-02-20)

## Scope completed

Wave-5 autonomous execution is completed for plugin/dev-server/docs scope, with backend runtime implementation still deferred.

Closed wave-5 objectives:

- plugin identity/idempotency hardening with enqueue/dispatch validation drops
- forced-team/slot-policy telemetry exposure via additive `constraint_signals`
- callback hot-path safety through cached policy context reads
- deterministic QA matrix rerun with strict and fixture-off plugin-only marker profiles
- canonical manual real-client matrix and evidence template standardization
- final contracts/docs/memory/handoff synchronization for user-run gameplay closure

## Changed file map (wave-5 scope)

### Plugin runtime + contract surfaces

- `pixel-control-plugin/src/PixelControlPlugin.php`
  - identity tuple validation on enqueue/dispatch
  - queue telemetry expansion (`dropped_on_identity_validation`)
  - additive `constraint_signals` and cached policy context reads
- `pixel-control-plugin/src/Api/EventEnvelope.php`
  - source callback/sequence getters used by identity validation flow
- `pixel-control-plugin/docs/event-contract.md`
- `pixel-control-plugin/FEATURES.md`
- `pixel-control-plugin/docs/schema/envelope-2026-02-20.1.schema.json`
- `pixel-control-plugin/docs/schema/lifecycle-payload-2026-02-20.1.schema.json`
- `pixel-control-plugin/docs/schema/event-name-catalog-2026-02-20.1.json`
- `pixel-control-plugin/docs/schema/delivery-error-2026-02-20.1.schema.json`

### Dev-server QA/manual workflow surfaces

- `pixel-sm-server/scripts/qa-wave4-telemetry-replay.sh`
  - marker profile support: `strict` and `plugin_only`
- `pixel-sm-server/scripts/manual-wave5-session-bootstrap.sh`
- `pixel-sm-server/scripts/manual-wave5-ack-stub.sh`
- `pixel-sm-server/scripts/manual-wave5-log-export.sh`
- `pixel-sm-server/scripts/manual-wave5-evidence-check.sh`
- `pixel-sm-server/README.md`

### Root-level contracts/planning/memory

- `API_CONTRACT.md`
- `ROADMAP.md`
- `AGENTS.md`
- `PLAN-autonomous-execution-wave-5.md`

### Deterministic and manual evidence artifacts

- `pixel-sm-server/logs/qa/wave5-evidence-index-20260220.md`
- `pixel-sm-server/logs/manual/wave5-real-client-20260220/README.md`
- `pixel-sm-server/logs/manual/wave5-real-client-20260220/MANUAL-TEST-MATRIX.md`
- `pixel-sm-server/logs/manual/wave5-real-client-20260220/INDEX.md`
- `pixel-sm-server/logs/manual/wave5-real-client-20260220/SESSION-session-001-notes.md`
- `pixel-sm-server/logs/manual/wave5-real-client-20260220/SESSION-session-001-payload.ndjson`
- `pixel-sm-server/logs/manual/wave5-real-client-20260220/SESSION-session-001-evidence.md`

## Verification commands and results

- `php -l pixel-control-plugin/src/PixelControlPlugin.php` -> pass
- `php -l pixel-control-plugin/src/Api/EventEnvelope.php` -> pass
- `bash -n pixel-sm-server/scripts/manual-wave5-session-bootstrap.sh` -> pass
- `bash -n pixel-sm-server/scripts/manual-wave5-evidence-check.sh` -> pass
- `bash pixel-sm-server/scripts/qa-launch-smoke.sh` -> pass (`pty_e8f46a44`, exit `0`)
- `bash pixel-sm-server/scripts/qa-mode-smoke.sh` -> pass (`pty_99a35038`, exit `0`)
- `bash pixel-sm-server/scripts/qa-wave4-telemetry-replay.sh` -> pass (`pty_041be617`, strict profile, exit `0`)
- `PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES=0 bash pixel-sm-server/scripts/qa-wave4-telemetry-replay.sh` -> pass (`pty_c1d410c5`, plugin-only profile, exit `0`)
- `bash pixel-sm-server/scripts/manual-wave5-evidence-check.sh --manual-dir pixel-sm-server/logs/manual/wave5-real-client-20260220` -> pass

Traceability note:

- Fixture-off replay pre-profile run failed as expected when strict marker closure was still enforced (`pty_e4d4563d`, exit `1`); profile gating now documents and supports fixture-off baseline validation.

## Deterministic evidence paths

Canonical wave-5 QA index:

- `pixel-sm-server/logs/qa/wave5-evidence-index-20260220.md`

Primary replay artifact sets:

- strict deterministic replay: `pixel-sm-server/logs/qa/wave4-telemetry-20260220-143317-{summary.md,markers.json,capture.ndjson,fixtures.ndjson}`
- fixture-off plugin-only baseline: `pixel-sm-server/logs/qa/wave4-telemetry-20260220-143433-{summary.md,markers.json,capture.ndjson}`

Smoke artifacts:

- launch smoke: `pixel-sm-server/logs/qa/qa-*-20260220-142315.{log,env}`
- mode matrix smoke: `pixel-sm-server/logs/qa/qa-*-20260220-1424*.{log,env}` through `pixel-sm-server/logs/qa/qa-*-20260220-1427*.{log,env}`

## Manual matrix quickstart (real clients)

1. Bootstrap templates:
   - `bash pixel-sm-server/scripts/manual-wave5-session-bootstrap.sh --date 20260220 --session-id session-001 --focus "stack-join-baseline"`
2. Start ACK capture:
   - `bash pixel-sm-server/scripts/manual-wave5-ack-stub.sh --output "pixel-sm-server/logs/manual/wave5-real-client-20260220/SESSION-session-001-payload.ndjson"`
3. Repoint plugin transport and sync:
   - `PIXEL_CONTROL_API_BASE_URL=http://host.docker.internal:18080 bash pixel-sm-server/scripts/dev-plugin-sync.sh`
4. Execute matrix scenarios `W5-M01..W5-M10` from:
   - `pixel-sm-server/logs/manual/wave5-real-client-20260220/MANUAL-TEST-MATRIX.md`
5. Export logs and validate contract:
   - `bash pixel-sm-server/scripts/manual-wave5-log-export.sh --manual-dir "pixel-sm-server/logs/manual/wave5-real-client-20260220" --session-id session-001`
   - `bash pixel-sm-server/scripts/manual-wave5-evidence-check.sh --manual-dir "pixel-sm-server/logs/manual/wave5-real-client-20260220"`

## Acceptance checklist

- [x] Plugin/dev-server/docs-only scope preserved (no backend runtime code added in `pixel-control-server/`)
- [x] Identity/idempotency guardrails and queue drop telemetry implemented
- [x] Forced-team/slot-policy telemetry semantics implemented with deterministic availability/fallback markers
- [x] Deterministic QA matrix rerun passed with indexed evidence
- [x] Wave-5 manual matrix and evidence templates standardized
- [ ] Real-client gameplay evidence captured and session status promoted from `planned` to `passed`/`failed`

## Closure notes for prior manual-pending plan items

- `PLAN-autonomous-execution-wave-1.md` (`P7.2`, `P7.3`) and `PLAN-autonomous-execution-wave-3.md` (`P6.2`) remain user-run manual gameplay closures.
- Wave-5 now provides the canonical matrix + storage contract to execute those remaining real-client validations without additional implementation work.

## Explicit next action for user

- Run one or more real-client gameplay sessions using `pixel-sm-server/logs/manual/wave5-real-client-20260220/MANUAL-TEST-MATRIX.md`, fill scenario evidence rows, and update `pixel-sm-server/logs/manual/wave5-real-client-20260220/INDEX.md` statuses.

## Backend reminder

- Backend/API runtime implementation remains deferred; continue tracking behavior/expectations in `API_CONTRACT.md` until backend work is explicitly reopened.
