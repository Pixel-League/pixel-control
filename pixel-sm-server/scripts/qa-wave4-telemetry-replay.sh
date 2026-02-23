#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

printf '[deprecated] scripts/qa-wave4-telemetry-replay.sh -> scripts/replay-extended-telemetry-wave4.sh\n' >&2
exec bash "${SCRIPT_DIR}/replay-extended-telemetry-wave4.sh" "$@"
