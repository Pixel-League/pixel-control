#!/usr/bin/env bash

matrix_run_step "status.initial" "$METHOD_STATUS"
matrix_refresh_status_from_last

if [[ "$MATRIX_STATUS_ACTIVE" == "1" ]]; then
  matrix_run_step "cancel.preflight.active_session" "$METHOD_CANCEL" \
    "reason=matrix_preflight_cleanup"
  matrix_run_step "status.preflight.after_cleanup" "$METHOD_STATUS"
  matrix_refresh_status_from_last
fi

matrix_run_step "action.no_session.initial" "$METHOD_ACTION" \
  "actor_login=${MATRIX_VOTER_A}" \
  "operation=action" \
  "map=1"

matrix_run_step "cancel.no_session.initial" "$METHOD_CANCEL" \
  "reason=matrix_no_session_initial"
