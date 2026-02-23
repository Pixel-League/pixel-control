#!/usr/bin/env bash

matrix_run_step "status.after_tournament" "$METHOD_STATUS"
matrix_refresh_status_from_last

if [[ "$MATRIX_STATUS_ACTIVE" == "1" ]]; then
  matrix_run_step "cancel.final_cleanup" "$METHOD_CANCEL" \
    "reason=matrix_cleanup_final"
fi

matrix_run_step "status.final" "$METHOD_STATUS"
matrix_refresh_status_from_last
