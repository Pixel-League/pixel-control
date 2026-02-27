#!/usr/bin/env bash

if [[ "${MATRIX_LINK_AUTH_CASE:-}" != "missing" ]]; then
  return 0
fi

matrix_step_expect_code 'match.bo.get' 'link_auth_missing'
