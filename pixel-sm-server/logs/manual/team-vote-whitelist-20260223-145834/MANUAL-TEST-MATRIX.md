# Team/Vote/Whitelist Manual Test Matrix (20260223-145834)

Use this matrix for remaining real-client closure of `P5.4.b`.

## Common prerequisites

- CP-1: `bash scripts/qa-launch-smoke.sh` passed recently.
- CP-2: stack is running and communication socket is reachable (`127.0.0.1:31501`).
- CP-3: at least two real accounts are available (one admin/operator, one non-admin player).
- CP-4: payload capture target for this session is active (`SESSION-<id>-payload.ndjson`).
- CP-5: whitelist/vote/team policy actions are reachable through `PixelControl.Admin.ExecuteAction`.

## Scenario matrix

| Scenario ID | Group | Prerequisites | Client/admin actions | Expected payload/log fields | Pass/fail criteria | Evidence destination |
| --- | --- | --- | --- | --- | --- | --- |
| TVW-M01 | whitelist-deny-real-login | CP-1, CP-2, CP-3, CP-4, CP-5 | Enable whitelist with target non-admin login NOT present; attempt join with non-whitelisted real account. | whitelist policy enabled snapshot, connect or info callback near deny window, deterministic deny signal (refused/kicked) in logs or payload context. | Pass if non-whitelisted real login is refused/kicked; fail on successful persistent join without explicit bypass. | SESSION-<id>-notes.md#tvw-m01-whitelist-deny-real-login + SESSION-<id>-evidence.md row TVW-M01 |
| TVW-M02 | whitelist-allow-control-login | CP-1, CP-2, CP-3, CP-4, CP-5 | Add control real login to whitelist and rejoin with that login. | whitelist snapshot contains control login, join/connect payload for control login, no deny markers for control login. | Pass if whitelisted control login joins and stays connected; fail on deny/kick of whitelisted login. | SESSION-<id>-notes.md#tvw-m02-whitelist-allow-control-login + SESSION-<id>-evidence.md row TVW-M02 |
| TVW-M03 | vote-policy-non-admin-block | CP-1, CP-2, CP-3, CP-4, CP-5 | Set vote policy mode (`cancel_non_admin_vote_on_callback`), have non-admin real account attempt vote initiation in live client UI/chat. | vote policy mode snapshot, vote callback evidence with non-admin initiator, deterministic block/cancel marker (`vote_cancelled` or strict-mode block evidence). | Pass if non-admin vote cannot persist and marker/evidence confirms policy action; fail on non-admin vote persisting without policy intervention. | SESSION-<id>-notes.md#tvw-m03-vote-policy-non-admin-block + SESSION-<id>-evidence.md row TVW-M03 |
| TVW-M04 | team-lock-elite | CP-1, CP-2, CP-3, CP-4, CP-5 | In Elite, assign real login to fixed team, attempt manual side switch from client, observe correction/lock behavior. | team roster snapshot with assignment, team policy enabled, side/team transition telemetry, enforcement markers when applicable. | Pass if unauthorized switch is corrected/blocked and assigned team is retained; fail on sustained unauthorized team state. | SESSION-<id>-notes.md#tvw-m04-team-lock-elite + SESSION-<id>-evidence.md row TVW-M04 |
| TVW-M05 | team-lock-secondary-mode | CP-1, CP-2, CP-3, CP-4, CP-5 | Repeat TVW-M04 in one additional available team mode (Joust/Siege/Battle). | same signal family as TVW-M04 in selected mode, mode context noted explicitly. | Pass if correction/lock behavior matches policy in selected team mode; fail on drift from assigned team without deterministic guard response. | SESSION-<id>-notes.md#tvw-m05-team-lock-secondary-mode + SESSION-<id>-evidence.md row TVW-M05 |

## Completion rule

- Mark `P5.4.b` complete only when TVW-M01..TVW-M05 are all marked `pass` with payload, log, and (when possible) screenshot/video references.
- If a scenario is blocked, document exact blocker and rerun path in `SESSION-<id>-notes.md` and keep `P5.4.b` open.
