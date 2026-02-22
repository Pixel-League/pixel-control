#!/usr/bin/env bash

if [[ "$MATRIX_STATUS_ACTIVE" == "1" ]]; then
  matrix_run_step "cancel.after_matchmaking" "$METHOD_CANCEL" \
    "reason=matrix_cleanup_after_matchmaking"
fi

matrix_run_step "cancel.no_session.post_matchmaking" "$METHOD_CANCEL" \
  "reason=matrix_no_session_post_matchmaking"
