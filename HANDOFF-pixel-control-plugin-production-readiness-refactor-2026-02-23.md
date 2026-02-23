# Handoff - Pixel Control plugin production-readiness refactor

Date: 2026-02-23

## Scope and outcome

- Refactor plan: `PLAN-pixel-control-plugin-production-readiness-refactor.md`
- Delivery status: phase 4 through phase 7 complete.
- Primary outcomes:
  - plugin-local quality gate and deterministic test harness delivered,
  - plugin docs consolidated into canonical `pixel-control-plugin/README.md`,
  - script naming migrated from `qa`/`smoke` to explicit command names with compatibility wrappers,
  - full validation re-run passed on renamed workflows.

## Architecture map (refactor target state)

- Plugin kernel and wiring:
  - `pixel-control-plugin/src/PixelControlPlugin.php`
  - core runtime helper boundary in `pixel-control-plugin/src/Domain/Core/CoreDomainTrait.php`
- Decomposed high-risk domain facades:
  - veto facade: `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php`
  - admin facade: `pixel-control-plugin/src/Domain/Admin/AdminControlDomainTrait.php`
  - player facade: `pixel-control-plugin/src/Domain/Player/PlayerDomainTrait.php`
  - match facade: `pixel-control-plugin/src/Domain/Match/MatchDomainTrait.php`
- Extracted focused subtraits (phase-2 architecture):
  - veto: `VetoDraftBootstrapTrait`, `VetoDraftIngressTrait`, `VetoDraftLifecycleTrait`, `VetoDraftAutostartTrait`
  - admin: `AdminControlBootstrapTrait`, `AdminControlExecutionTrait`, `AdminControlIngressTrait`
  - player: `PlayerSourceSnapshotTrait`, `PlayerContinuityCorrelationTrait`, `PlayerPolicySignalsTrait`
  - match: `MatchAggregateTelemetryTrait`, `MatchWinContextTrait`, `MatchVetoRotationTrait`

## Script naming migration map (active surfaces)

- `scripts/qa-admin-payload-sim.sh` -> `scripts/simulate-admin-control-payloads.sh`
- `scripts/qa-veto-payload-sim.sh` -> `scripts/simulate-veto-control-payloads.sh`
- `scripts/qa-admin-stats-replay.sh` -> `scripts/replay-admin-player-combat-telemetry.sh`
- `scripts/qa-launch-smoke.sh` -> `scripts/validate-dev-stack-launch.sh`
- `scripts/qa-mode-smoke.sh` -> `scripts/validate-mode-launch-matrix.sh`
- `scripts/qa-wave3-telemetry-replay.sh` -> `scripts/replay-core-telemetry-wave3.sh`
- `scripts/qa-wave4-telemetry-replay.sh` -> `scripts/replay-extended-telemetry-wave4.sh`
- `scripts/qa-admin-matrix-actions/` -> `scripts/admin-action-matrix-steps/`
- `scripts/qa-veto-matrix-actions/` -> `scripts/veto-action-matrix-steps/`

Compatibility bridge:

- Old script names remain as wrappers with deprecation message + argument passthrough.
- Deprecated suite flag `--with-mode-smoke` still works and maps to `--with-mode-matrix-validation`.

## Documentation consolidation (phase 5 retained/removed)

Canonical retained docs:

- `pixel-control-plugin/README.md`
- `pixel-control-plugin/docs/event-contract.md`
- `pixel-control-plugin/docs/schema/*.json`

Merged/removed docs:

- `pixel-control-plugin/FEATURES.md`
- `pixel-control-plugin/docs/admin-capability-delegation.md`
- `pixel-control-plugin/docs/veto-system-test-checklist.md`
- `pixel-control-plugin/docs/manual-feature-test-todo.md`
- `pixel-control-plugin/docs/audit/maniacontrol-admin-capability-audit-2026-02-20.md`
- `pixel-control-plugin/docs/audit/team-vote-whitelist-capability-audit-2026-02-23.md`

## Validation evidence

- Plugin quality gate:
  - command: `bash pixel-control-plugin/scripts/check-quality.sh`
  - result: `Lint OK for 74 files.` and `Result: passed=20, failed=0, total=20`
- Renamed script/surface validation:
  - command: `bash pixel-sm-server/scripts/test-automated-suite.sh --help`
  - result: canonical option `--with-mode-matrix-validation` exposed, deprecated alias retained
  - command: `bash pixel-sm-server/scripts/simulate-admin-control-payloads.sh --help`
  - command: `bash pixel-sm-server/scripts/simulate-veto-control-payloads.sh --help`
  - result: canonical commands and canonical matrix-step default directories exposed
- Full orchestrated validation on renamed flows:
  - command: `bash pixel-sm-server/scripts/test-automated-suite.sh --modes elite,joust`
  - artifacts:
    - `pixel-sm-server/logs/qa/automated-suite-20260223-203704/suite-summary.json`
    - `pixel-sm-server/logs/qa/automated-suite-20260223-203704/suite-summary.md`
    - `pixel-sm-server/logs/qa/automated-suite-20260223-203704/check-results.ndjson`
  - summary: `total_checks=39`, `passed_checks=39`, `required_failed_checks=0`

## Compatibility boundary confirmation

- Plugin source runtime contracts were not intentionally changed in phase-6/phase-7 scope.
- Admin/veto method names remain stable:
  - `PixelControl.Admin.*`
  - `PixelControl.VetoDraft.*`
- Command surface remains stable:
  - `pcadmin`
  - `pcveto`
- Schema baseline remains stable:
  - `2026-02-20.1`

## Known follow-ups

- Keep wrappers until explicit migration-window closure; remove only in a dedicated cleanup phase.
- Historical plan/handoff artifacts still reference old names by design (historical evidence, not active operator surface).
- Manual real-client combat observability remains manual-only validation scope (`OnShoot`, `OnHit`, `OnNearMiss`, `OnArmorEmpty`, `OnCapture`).
