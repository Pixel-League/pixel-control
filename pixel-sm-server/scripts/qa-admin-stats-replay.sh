#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

printf '[deprecated] scripts/qa-admin-stats-replay.sh -> scripts/replay-admin-player-combat-telemetry.sh\n' >&2
exec bash "${SCRIPT_DIR}/replay-admin-player-combat-telemetry.sh" "$@"
