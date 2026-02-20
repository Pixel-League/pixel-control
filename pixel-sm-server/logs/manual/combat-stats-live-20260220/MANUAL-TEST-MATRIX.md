# Combat Stats Live Manual Test Matrix (20260220)

Use this matrix for real-client validation of live combat stats only.

## Common prerequisites

- CP-1: `bash scripts/qa-launch-smoke.sh` passed recently.
- CP-2: local ACK stub capture is running for the target session payload file.
- CP-3: plugin transport targets the stub (`PIXEL_CONTROL_API_BASE_URL=http://host.docker.internal:18080`).
- CP-4: one remote real client has joined the server from another LAN machine and attested join success.
- CP-5: test flow runs in warmup first, then in a live match segment.

## Scenario matrix

| Scenario ID | Group | Prerequisites | Client/admin actions | Expected payload/log fields | Pass/fail criteria | Evidence destination |
| --- | --- | --- | --- | --- | --- | --- |
| CSM-M01 | stack-join-baseline | CP-1, CP-2, CP-3, CP-4 | Start stack, join remote client, wait for connect/info callbacks. | plugin load marker in ManiaControl log, player connect entries, deterministic identity tuple in envelopes. | Pass if player join is visible in logs/payload and plugin is loaded; fail on missing join evidence. | `SESSION-<id>-notes.md#csm-m01-stack-join-baseline` + row CSM-M01 |
| CSM-M02 | warmup-leakage-probe | CP-1..CP-5 | During warmup, fire exactly 2 rockets and 2 lasers; attempt 1 hit with each weapon. | warmup lifecycle context around action window, counters (`rockets`, `lasers`, `shots`, `hits`), `dimensions.weapon_id` (`2` rocket, `1` laser). | Pass if all four counter deltas stay `0`; fail if any warmup delta is `>0`. | `SESSION-<id>-notes.md#csm-m02-warmup-leakage-probe` + row CSM-M02 |
| CSM-M03 | live-rocket-loop | CP-1..CP-5 | In live round, fire exactly 4 rocket shots and report hit markers. | `event_name` shoot/hit events, `dimensions.weapon_id=2`, player counter deltas. | Pass if `delta_rockets=4`, `delta_shots=4`, `delta_lasers=0`, `delta_hits` matches reported hits. | `SESSION-<id>-notes.md#csm-m03-live-rocket-loop` + row CSM-M03 |
| CSM-M04 | live-laser-loop | CP-1..CP-5 | In live round, fire exactly 4 laser shots and report hit markers. | `event_name` shoot/hit events, `dimensions.weapon_id=1`, player counter deltas. | Pass if `delta_lasers=4`, `delta_shots=4`, `delta_rockets=0`, `delta_hits` matches reported hits. | `SESSION-<id>-notes.md#csm-m04-live-laser-loop` + row CSM-M04 |
| CSM-M05 | live-mixed-loop | CP-1..CP-5 | In one live segment, fire 2 rockets then 2 lasers; at least 2 hits total. | weapon split via `dimensions.weapon_id`, player counter deltas. | Pass if `delta_rockets=2`, `delta_lasers=2`, `delta_shots=4`, `delta_hits>=2 && <=4`. | `SESSION-<id>-notes.md#csm-m05-live-mixed-loop` + row CSM-M05 |
| CSM-M06 | threshold-review | CP-1..CP-5 | Aggregate warmup and live loop outputs and finalize verdict. | scenario evidence rows + session logs/payload references. | Pass if all scoped metric thresholds are met; otherwise fail with correction ticket details. | `SESSION-<id>-notes.md#csm-m06-threshold-review` + row CSM-M06 |

## Completion rule

- Mark a scenario pass only after payload references, log references, and optional media references are present in session files.
- If a live loop fails once, rerun exactly once with a fresh baseline before final fail verdict.
