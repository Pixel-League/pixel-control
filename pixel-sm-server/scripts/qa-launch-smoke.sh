#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

printf '[deprecated] scripts/qa-launch-smoke.sh -> scripts/validate-dev-stack-launch.sh\n' >&2
exec bash "${SCRIPT_DIR}/validate-dev-stack-launch.sh" "$@"
