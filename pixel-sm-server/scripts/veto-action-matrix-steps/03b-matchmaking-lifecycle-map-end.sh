#!/usr/bin/env bash

if [[ "$MATRIX_MATCHMAKING_STARTED" -ne 1 ]]; then
  warn "Skipping matchmaking lifecycle map-end smoke; matchmaking did not start."
  return 0
fi

matrix_run_step "status.matchmaking.lifecycle.before_map_end" "$METHOD_STATUS"
matrix_refresh_status_from_last

if ! matrix_trigger_matchmaking_map_end; then
  warn "Selected-map end trigger failed; lifecycle ready-state check may remain incomplete."
fi

if ! matrix_wait_for_matchmaking_lifecycle_completion 25; then
  warn "Lifecycle completion did not converge to ready_for_next_players within poll window."
fi

matrix_run_step "status.matchmaking.lifecycle.after_map_end" "$METHOD_STATUS"
matrix_refresh_status_from_last
