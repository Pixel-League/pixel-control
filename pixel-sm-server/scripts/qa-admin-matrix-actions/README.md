# QA Admin Matrix Action Specs

This directory defines the matrix replay order for `scripts/qa-admin-payload-sim.sh matrix`.

## Contract

- One `.sh` file per action/step.
- Files are loaded in lexical order.
- Each file is sourced by `qa-admin-payload-sim.sh` and must call `matrix_step ...` exactly once.
- Matrix runtime variables are available in scope:
  - `MATRIX_TARGET_LOGIN`, `MATRIX_MAP_UID`, `MATRIX_MX_ID`, `MATRIX_TEAM`,
  - `MATRIX_AUTH_LEVEL`, `MATRIX_VOTE_COMMAND`, `MATRIX_VOTE_RATIO`, `MATRIX_VOTE_INDEX`,
  - `MATRIX_BO`, `MATRIX_MAPS_SCORE`, `MATRIX_ROUND_SCORE`.

## Why

- Keeps action coverage modular and reviewable.
- Allows adding/removing/reordering matrix steps without editing the monolithic script body.
