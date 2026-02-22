#!/usr/bin/env bash

if [[ "$MATRIX_MATCHMAKING_STARTED" -eq 1 ]]; then
  matrix_run_step "action.matchmaking.vote.voter_a" "$METHOD_ACTION" \
    "actor_login=${MATRIX_VOTER_A}" \
    "operation=vote" \
    "map=1"

  matrix_run_step "action.matchmaking.vote.voter_b" "$METHOD_ACTION" \
    "actor_login=${MATRIX_VOTER_B}" \
    "operation=vote" \
    "map=2"

  matrix_run_step "action.matchmaking.vote.voter_c" "$METHOD_ACTION" \
    "actor_login=${MATRIX_VOTER_C}" \
    "operation=vote" \
    "map=2"

  matrix_wait_for_matchmaking_closure
else
  warn "Skipping matchmaking votes; matchmaking did not start (code=${MATRIX_LAST_ACTION_CODE:-unknown})."
fi

matrix_run_step "status.after_matchmaking" "$METHOD_STATUS"
matrix_refresh_status_from_last
