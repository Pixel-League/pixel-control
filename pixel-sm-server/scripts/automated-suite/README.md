# Automated Suite Modules

This directory stores modular action/feature descriptors used by `scripts/test-automated-suite.sh`.

## Admin action descriptors

- Path: `scripts/automated-suite/admin-actions/*.sh`
- Contract: each script must print exactly one admin action key to stdout (for example `match.bo.set`) and exit `0`.
- The automated suite loads these scripts dynamically and uses the resulting list to validate `PixelControl.Admin.ListActions` coverage.

## Veto required-check descriptors

- Path: `scripts/automated-suite/veto-checks/*.sh`
- Contract: each script must print exactly one required veto matrix check id to stdout (for example `flow.matchmaking.start`) and exit `0`.
- The automated suite loads these scripts dynamically and validates the generated `matrix-validation.json` from `qa-veto-payload-sim.sh matrix` against this required check list.
- This keeps veto required coverage extension modular and avoids hardcoding check inventories inside the suite runner.

## Why this exists

- Keeps the admin action list out of the monolithic test runner.
- Enables one-file-per-action maintenance.
- Makes action additions/removals localized and reviewable.

## Add a new admin action check

1. Add a new `*.sh` file under `admin-actions/`.
2. Print the canonical action key.
3. Ensure the action is also exercised by `scripts/qa-admin-payload-sim.sh matrix` if runtime execution coverage is required.

## Add a new veto required check

1. Add a new `*.sh` file under `veto-checks/`.
2. Print the canonical veto check id emitted by `matrix-validation.json`.
3. Ensure `scripts/qa-veto-payload-sim.sh matrix` emits and evaluates that check id.

## Related matrix step modules

- Runtime matrix execution order is defined separately in `scripts/qa-admin-matrix-actions/`.
- That directory also follows one-file-per-action-step and is consumed by `scripts/qa-admin-payload-sim.sh matrix`.
- Veto matrix execution order is defined in `scripts/qa-veto-matrix-actions/` and consumed by `scripts/qa-veto-payload-sim.sh matrix`.
- Veto matrix strict machine-readable assertions are emitted to `matrix-validation.json`; automated suite veto descriptors in `veto-checks/` must reference check ids from that artifact.

## Add a new veto matrix runtime step

1. Add a new lexically ordered `*.sh` module under `scripts/qa-veto-matrix-actions/`.
2. Use helper functions exposed by `scripts/qa-veto-payload-sim.sh` (step registration + expected-code assertions).
3. Re-run `bash scripts/qa-veto-payload-sim.sh matrix` and confirm the new step appears in `summary.md` and `matrix-step-manifest.ndjson`.
4. If the step introduces a required regression gate, add/update a matching descriptor in `scripts/automated-suite/veto-checks/` and verify `bash scripts/test-automated-suite.sh --modes elite,joust`.
