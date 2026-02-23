#!/usr/bin/env bash

if [[ "$MATRIX_MATCHMAKING_STARTED" -eq 1 ]]; then
  matrix_run_step "start.matchmaking.post_cycle_without_ready" "$METHOD_START" \
    "mode=matchmaking_vote" \
    "duration_seconds=${MATRIX_MATCHMAKING_DURATION}" \
    "launch_immediately=0"

  matrix_run_step "ready.matchmaking.rearm" "$METHOD_READY"

  matrix_run_step "action.matchmaking.vote.rearmed_bootstrap" "$METHOD_ACTION" \
    "actor_login=${MATRIX_VOTER_A}" \
    "operation=vote" \
    "map=1"

  if [[ "$MATRIX_LAST_ACTION_SUCCESS" == "true" && "$MATRIX_LAST_ACTION_CODE" == "vote_recorded" ]]; then
    matrix_run_step "cancel.after_rearmed_bootstrap" "$METHOD_CANCEL" \
      "reason=matrix_cleanup_after_rearmed_bootstrap"
  fi

  matrix_run_step "status.matchmaking.after_rearmed_bootstrap" "$METHOD_STATUS"
  matrix_refresh_status_from_last
fi

if [[ "$MATRIX_STATUS_ACTIVE" == "1" ]]; then
  matrix_run_step "cancel.after_matchmaking" "$METHOD_CANCEL" \
    "reason=matrix_cleanup_after_matchmaking"
fi

matrix_run_step "cancel.no_session.post_matchmaking" "$METHOD_CANCEL" \
  "reason=matrix_no_session_post_matchmaking"
