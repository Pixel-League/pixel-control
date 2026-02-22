#!/usr/bin/env bash

matrix_run_step "start.matchmaking" "$METHOD_START" \
  "mode=matchmaking_vote" \
  "duration_seconds=${MATRIX_MATCHMAKING_DURATION}" \
  "launch_immediately=0"

if [[ "$MATRIX_LAST_ACTION_SUCCESS" == "true" && "$MATRIX_LAST_ACTION_CODE" == "matchmaking_started" ]]; then
  MATRIX_MATCHMAKING_STARTED=1
else
  MATRIX_MATCHMAKING_STARTED=0
fi

if [[ "$MATRIX_LAST_ACTION_SUCCESS" == "false" && "$MATRIX_LAST_ACTION_CODE" == "map_pool_too_small" ]]; then
  MATRIX_MATCHMAKING_LIMITED_MODE=1
else
  MATRIX_MATCHMAKING_LIMITED_MODE=0
fi

if [[ "$MATRIX_MATCHMAKING_STARTED" -eq 1 ]]; then
  matrix_run_step "start.matchmaking.conflict" "$METHOD_START" \
    "mode=matchmaking_vote" \
    "duration_seconds=${MATRIX_MATCHMAKING_DURATION}" \
    "launch_immediately=0"
else
  warn "Skipping start.matchmaking.conflict; matchmaking did not start (code=${MATRIX_LAST_ACTION_CODE:-unknown})."
fi
