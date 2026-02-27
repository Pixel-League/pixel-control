# Automated Suite Modules

This directory stores modular action/feature descriptors used by `scripts/test-automated-suite.sh`.

## Admin action descriptors

- Path: `scripts/automated-suite/admin-actions/*.sh`
- Contract: each script must print exactly one admin action key to stdout (for example `match.bo.set`) and exit `0`.
- The automated suite loads these scripts dynamically and uses the resulting list to validate `PixelControl.Admin.ListActions` coverage.

## Veto required-check descriptors

- Path: `scripts/automated-suite/veto-checks/*.sh`
- Contract: each script must print exactly one required veto matrix check id to stdout (for example `flow.matchmaking.start`) and exit `0`.
- The automated suite loads these scripts dynamically and validates the generated `matrix-validation.json` from `simulate-veto-control-payloads.sh matrix` against this required check list.
- This keeps veto required coverage extension modular and avoids hardcoding check inventories inside the suite runner.

## Admin link-auth case descriptors

- Path: `scripts/automated-suite/admin-link-auth-cases/*.sh`
- Contract: each script must print exactly one link-auth case id to stdout (`missing`, `invalid`, `mismatch`, or `valid`) and exit `0`.
- The automated suite loads these scripts dynamically and runs `simulate-admin-control-payloads.sh matrix link_auth_case=<id>` as required checks.
- This keeps link-auth negative/positive coverage modular instead of hardcoding case lists in the suite runner.

## Why this exists

- Keeps the admin action list out of the monolithic test runner.
- Enables one-file-per-action maintenance.
- Makes action additions/removals localized and reviewable.

## Add a new admin action check

1. Add a new `*.sh` file under `admin-actions/`.
2. Print the canonical action key.
3. Ensure the action is also exercised by `scripts/simulate-admin-control-payloads.sh matrix` if runtime execution coverage is required.

## Add a new veto required check

1. Add a new `*.sh` file under `veto-checks/`.
2. Print the canonical veto check id emitted by `matrix-validation.json`.
3. Ensure `scripts/simulate-veto-control-payloads.sh matrix` emits and evaluates that check id.

## Add a new admin link-auth case check

1. Add a new `*.sh` file under `admin-link-auth-cases/`.
2. Print one supported case id (`missing`, `invalid`, `mismatch`, `valid`).
3. Ensure `scripts/simulate-admin-control-payloads.sh` supports that case and emits `matrix-validation.json` assertions.

## Related matrix step modules

- Runtime matrix execution order is defined separately in `scripts/admin-action-matrix-steps/`.
- That directory also follows one-file-per-action-step and is consumed by `scripts/simulate-admin-control-payloads.sh matrix`.
- Veto matrix execution order is defined in `scripts/veto-action-matrix-steps/` and consumed by `scripts/simulate-veto-control-payloads.sh matrix`.
- Veto matrix strict machine-readable assertions are emitted to `matrix-validation.json`; automated suite veto descriptors in `veto-checks/` must reference check ids from that artifact.

## Add a new veto matrix runtime step

1. Add a new lexically ordered `*.sh` module under `scripts/veto-action-matrix-steps/`.
2. Use helper functions exposed by `scripts/simulate-veto-control-payloads.sh` (step registration + expected-code assertions).
3. Re-run `bash scripts/simulate-veto-control-payloads.sh matrix` and confirm the new step appears in `summary.md` and `matrix-step-manifest.ndjson`.
4. If the step introduces a required regression gate, add/update a matching descriptor in `scripts/automated-suite/veto-checks/` and verify `bash scripts/test-automated-suite.sh --modes elite,joust`.
