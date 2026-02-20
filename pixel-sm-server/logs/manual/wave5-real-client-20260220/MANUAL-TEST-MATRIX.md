# Wave 5 Manual Test Matrix (20260220)

Use this matrix for real-client validation. Run all scenarios before marking wave-5 manual closure complete.

## Common prerequisites

- CP-1: bash scripts/qa-launch-smoke.sh passed recently.
- CP-2: local ACK stub capture is running for the target session payload file.
- CP-3: plugin transport targets the stub (PIXEL_CONTROL_API_BASE_URL=http://host.docker.internal:18080).
- CP-4: stack is running in a mode compatible with the scenario (veto/draft scenarios require mode+map flow exposing map rotation callbacks).
- CP-5: required operators are present (at least one admin actor and one or more gameplay clients).

## Scenario matrix

| Scenario ID | Group | Prerequisites | Client/admin actions | Expected payload/log fields | Pass/fail criteria | Evidence destination |
| --- | --- | --- | --- | --- | --- | --- |
| W5-M01 | stack-join-baseline | CP-1, CP-2, CP-3 | Start stack, join one client, wait for player connect/info callbacks. | envelope identity tuple (event_name, event_id, idempotency_key), player identity fields, plugin load marker in ManiaControl log. | Pass if join emits deterministic identity fields and no malformed-envelope drops; fail on missing player join payload or identity drift. | SESSION-<id>-notes.md#w5-m01-stack-join-baseline + SESSION-<id>-evidence.md row W5-M01 |
| W5-M02 | admin-flow-actions | CP-1, CP-2, CP-3, CP-5 | Trigger admin actions (for example force spectator/team or map restart) through ManiaControl/admin flow. | lifecycle.admin_action context, player.transition/admin correlation fields, queue health markers if retries occur. | Pass if admin actions are reflected in payloads/logs with clear actor/action semantics; fail on missing/ambiguous admin context. | SESSION-<id>-notes.md#w5-m02-admin-flow-actions + SESSION-<id>-evidence.md row W5-M02 |
| W5-M03 | live-combat-counters | CP-1, CP-2, CP-3, CP-5 | Run short duel/skirmish producing shots, hits, misses, and kills. | combat.player_counters (shots, hits, misses, kills, deaths, accuracy), dimensions.weapon_id/damage/distance, player references. | Pass if counters are non-zero where expected and dimensions are populated with deterministic fallback semantics; fail on stale/zero-only counters despite confirmed combat. | SESSION-<id>-notes.md#w5-m03-live-combat-counters + SESSION-<id>-evidence.md row W5-M03 |
| W5-M04 | reconnect-continuity | CP-1, CP-2, CP-3, CP-5 | Disconnect and reconnect same client login during active session. | player.reconnect_continuity (identity_key, session_id, session_ordinal, transition_state), ordering fields. | Pass if reconnect chain increments deterministically and links to same identity key; fail on broken chain or missing reconnect semantics. | SESSION-<id>-notes.md#w5-m04-reconnect-continuity + SESSION-<id>-evidence.md row W5-M04 |
| W5-M05 | side-team-transitions | CP-1, CP-2, CP-3, CP-5 | Perform side/team switch via gameplay/admin path. | player.side_change (previous/current team+side, transition_kind, detected/team_changed/side_changed, dedupe_key). | Pass if side/team change is captured once per transition with coherent before/after values; fail on missing or contradictory side/team projection. | SESSION-<id>-notes.md#w5-m05-side-team-transitions + SESSION-<id>-evidence.md row W5-M05 |
| W5-M06 | team-aggregates | CP-1, CP-2, CP-3, CP-5 | Complete at least one round/map boundary with gameplay events. | lifecycle.aggregate_stats.team_counters_delta, team_summary, boundary window metadata. | Pass if aggregate deltas align with observed round/map activity and coverage markers are present; fail on empty or inconsistent team aggregates. | SESSION-<id>-notes.md#w5-m06-team-aggregates + SESSION-<id>-evidence.md row W5-M06 |
| W5-M07 | win-context | CP-1, CP-2, CP-3, CP-5 | Reach a boundary producing winner/tie context. | lifecycle.aggregate_stats.win_context (result_state, winning_side, winning_reason, fallback markers). | Pass if win context matches observed outcome (win/loss/tie) with deterministic reason fields; fail on wrong side/result mapping. | SESSION-<id>-notes.md#w5-m07-win-context + SESSION-<id>-evidence.md row W5-M07 |
| W5-M08 | veto-draft-actions | CP-1, CP-2, CP-3, CP-4, CP-5 | Run map veto/draft sequence with explicit admin/player actions where supported. | lifecycle.map_rotation.veto_draft_actions entries (action type, actor, order, fallback markers). | Pass if veto/pick/pass/lock actions are emitted in deterministic order (or explicit fallback semantics when unavailable); fail on silent action loss. | SESSION-<id>-notes.md#w5-m08-veto-draft-actions + SESSION-<id>-evidence.md row W5-M08 |
| W5-M09 | veto-result | CP-1, CP-2, CP-3, CP-4, CP-5 | Complete veto/draft flow until selected map result is known. | lifecycle.map_rotation.veto_result (status, selected map metadata, partial/unavailable semantics). | Pass if final veto result status matches observed flow and selected map projection is coherent; fail on mismatched result status or missing final projection. | SESSION-<id>-notes.md#w5-m09-veto-result + SESSION-<id>-evidence.md row W5-M09 |
| W5-M10 | outage-recovery-replay | CP-1, CP-2, CP-3 | Stop ACK stub to force transport outage, perform actions, restart stub, verify flush/recovery. | queue/outage telemetry (outage_entered, retry_scheduled, outage_recovered, recovery_flush_complete), queue depth + dropped counters. | Pass if outage markers appear in order and backlog flush completes after recovery; fail if queue does not recover or markers are missing/out-of-order. | SESSION-<id>-notes.md#w5-m10-outage-recovery-replay + SESSION-<id>-evidence.md row W5-M10 |

## Completion rule

- Mark a scenario pass only after payload, logs, and media evidence references are all present in session files.
- If a scenario cannot be executed in current runtime mode, mark fail or blocked with explicit reason and rerun instructions.
