#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

printf '[deprecated] scripts/qa-mode-smoke.sh -> scripts/validate-mode-launch-matrix.sh\n' >&2
exec bash "${SCRIPT_DIR}/validate-mode-launch-matrix.sh" "$@"
