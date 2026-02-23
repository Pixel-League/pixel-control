# Veto Matrix Step Specs

This directory defines the matrix replay order for `scripts/simulate-veto-control-payloads.sh matrix`.

## Contract

- One `.sh` file per matrix step/scenario.
- Files are loaded in lexical order.
- Each file is sourced by `simulate-veto-control-payloads.sh`.
- Step scripts should call the helper functions exposed by the simulator (`matrix_run_step`, `matrix_expect_last_*`, etc.).

## Why

- Keeps veto coverage modular and reviewable.
- Allows adding/removing/reordering matrix steps without editing the monolithic script body.
