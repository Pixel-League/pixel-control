# PLAN - Enforce veto role visibility, dynamic countdown cadence, and per-session vote reset (2026-02-22)

## Context

- Purpose: define an execution-ready PLAN_EXECUTE handoff for the new veto UX/privacy request: hide map IDs from normal players, keep sensitive completion/status lines admin-only, extend countdown cadence from configured duration, and guarantee vote counters reset to zero for each new veto session.
- Scope: `pixel-control-plugin/` implementation + QA evidence + behavior/contract documentation updates; no backend runtime work in `pixel-control-server/`.
- Background / findings:
  - `sendCurrentMapPoolToPlayer(...)` and `broadcastVetoDraftSessionOverview()` currently print map rows via `MapPoolService::buildMapListRows(...)`, which appends `[map_uid]` for everyone.
  - Completion observability lines (`Series order`, `Completion branch`, `Opener jump`) are currently broadcast globally.
  - `/pcveto status` currently shows operator-level lines (`Map draft/veto status`, `No active session`, `Series config`) to every caller.
  - Matchmaking countdown checkpoints are currently fixed in `VetoDraftCatalog::MATCHMAKING_COUNTDOWN_SECONDS` (`40,30,20,10,5..1`) instead of being generated from configured session duration.
  - Matchmaking vote counters are initialized in session state; this scope requires an explicit no-regression guarantee that a fresh session always starts with zero counts.
- Goals:
  - Normal players never see map UIDs in veto map listings; admin audience still receives UID-capable listings.
  - Chat lines `Series order`, `Completion branch`, and `Opener jump` are visible only to admin audience.
  - Countdown announcements occur at every 10-second boundary from configured duration down to `10`, then `5/4/3/2/1` (example `120,110,...,10,5,4,3,2,1`), without duplicate per-session spam.
  - `/pcveto status` for normal players is reduced to veto-result-centric output; admin keeps operational lines.
  - Each new veto session starts with vote totals reset to `0` for all pool maps.
- Non-goals:
  - No changes to plugin->API route definitions.
  - No change to tournament sequencing or queue-apply branch semantics beyond visibility filtering.
  - No edits under `ressources/`.
- Constraints / assumptions:
  - Default implementation assumption: "admin-only" visibility is gated by veto control permission (`VetoDraftCatalog::RIGHT_CONTROL`) unless product direction explicitly tightens to another auth level.
  - Communication payloads remain additive and backward compatible; this scope focuses on chat/command-view visibility.
  - PLAN_EXECUTE workflow requirement is satisfied by this planning artifact only; execution is delegated to Executor after handoff.

## Steps

Execution rule: planning artifact only. Executor keeps one active `[In progress]` step at a time and updates markers live during implementation.

- [Done] Phase 0 - Freeze visibility and compatibility contract
- [Done] Phase 1 - Implement role-based map/summary visibility in chat output
- [Done] Phase 2 - Implement dynamic countdown generation from configured duration
- [Done] Phase 3 - Enforce and verify per-session vote counter reset behavior
- [Done] Phase 4 - QA verification and evidence capture
- [Done] Phase 5 - Documentation/contract sync and handoff

### Phase 0 - Freeze visibility and compatibility contract

Acceptance criteria: implementation contract is explicit for role mapping, player/admin output boundaries, and compatibility expectations.

- [Done] P0.1 - Freeze role mapping for "admin-only" visibility.
  - Confirm which permission boundary controls privileged visibility (`RIGHT_CONTROL` baseline assumption), and document it before code edits.
  - Decision (executor): admin-only visibility stays bound to `VetoDraftCatalog::RIGHT_CONTROL` via `hasVetoControlPermission(...)`; no stricter auth gate introduced in this scope.
- [Done] P0.2 - Freeze `/pcveto status` output matrix by caller role.
  - Admin output keeps operational diagnostics.
  - Player output is limited to veto result lines only (no `Map draft/veto status`, no `No active session`, no `Series config`).
  - Decision (executor): non-admin `status` returns veto-result projection lines only (`running|completed|cancelled|unavailable` + final selection context when available); admin retains current operational diagnostics and series snapshot.
- [Done] P0.3 - Freeze session-overview visibility matrix.
  - Public lines vs admin-only lines are documented for start/completion flows.
  - Decision (executor): public overview keeps mode/status + gameplay guidance + map names (index/name only), while admin addendum keeps UID-bearing map listings and completion diagnostics (`Series order`, `Completion branch`, `Opener jump`).
- [Done] P0.4 - Freeze compatibility policy for communication status payload.
  - Chat visibility changes must not remove expected fields from `PixelControl.VetoDraft.Status` unless explicitly approved.
  - Decision (executor): communication/list payload shapes remain unchanged; visibility tightening is chat/control-surface only.

### Phase 1 - Implement role-based map/summary visibility in chat output

Acceptance criteria: role-scoped chat output is deterministic; sensitive identifiers/details are not exposed to normal players.

- [Done] P1.1 - Add role-aware map row rendering.
  - Touchpoint: `pixel-control-plugin/src/VetoDraft/MapPoolService.php`.
  - Introduce a formatter option (or dedicated method) to render rows with or without map UID suffix.
- [Done] P1.2 - Apply role-aware map rendering to player and session-overview outputs.
  - Touchpoint: `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php` (`sendCurrentMapPoolToPlayer`, `broadcastVetoDraftSessionOverview`).
  - Normal players receive index+name rows; admin audience receives index+name+UID rows.
- [Done] P1.3 - Restrict completion-detail lines to admin audience.
  - Touchpoint: `handleDraftCompletionIfNeeded(...)` in `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php`.
  - Keep generic completion success line public, move `Series order`, `Completion branch`, `Opener jump` to admin-only channel.
- [Done] P1.4 - Restrict `/pcveto status` operational lines to admin callers.
  - Touchpoint: `sendVetoDraftStatusToPlayer(...)` in `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php`.
  - Player output must focus on veto result only; admin output preserves operational diagnostics and series snapshot.

### Phase 2 - Implement dynamic countdown generation from configured duration

Acceptance criteria: matchmaking countdown emits at deterministic thresholds derived from current session duration with per-session dedupe.

- [Done] P2.1 - Replace fixed countdown list logic with dynamic threshold generation.
  - Touchpoints: `pixel-control-plugin/src/VetoDraft/VetoDraftCoordinator.php` and `pixel-control-plugin/src/VetoDraft/VetoDraftCatalog.php`.
  - Generate checkpoints: `<duration>, <duration-10>, ... , 10, 5, 4, 3, 2, 1` (bounded to positive values).
- [Done] P2.2 - Preserve and validate dedupe/off-by-one behavior.
  - Ensure one announcement per `<session_id, remaining_second>` even with timer jitter.
  - Validate edge durations (`10`, `15`, `60`, `120`) to avoid skipped or duplicate checkpoints.
  - Executor check: `php -r` schedule probe confirms `10=>10,5..1`, `15=>15,10,5..1`, `60=>60,50..10,5..1`, `120=>120,110..10,5..1`; dedupe path remains session+remaining-second keyed in coordinator state.
- [Done] P2.3 - Keep countdown message template unchanged unless required.
  - Maintain current chat/log prefix conventions and additive observability markers.

### Phase 3 - Enforce and verify per-session vote counter reset behavior

Acceptance criteria: every new matchmaking session begins with zero vote totals regardless of previous session history.

- [Done] P3.1 - Audit start path and session state initialization for reset guarantees.
  - Touchpoints: `pixel-control-plugin/src/VetoDraft/MatchmakingVoteSession.php`, `pixel-control-plugin/src/VetoDraft/VetoDraftCoordinator.php`.
- [Done] P3.2 - Add explicit guard/reset if any stale carry-over path exists.
  - Guarantee `vote_totals[*].vote_count=0` immediately after session start.
  - Executor implementation: coordinator now hard-resets vote counters on matchmaking start (`resetVoteCounters()`), revalidates zero baseline, and keeps only zeroed session snapshot for downstream status.
- [Done] P3.3 - Add deterministic status/checkpoint verification logic for new-session zero baselines.
  - Confirm first status snapshot after start reports zero counts before first vote.
  - Executor evidence: communication replay at `pixel-sm-server/logs/qa/veto-role-visibility-countdown-reset/veto-payload-sim-20260222-135328/start.json` and `...-135330/status.json` (all vote counts zero on first snapshot), then post-vote status `...-135333/status.json` (non-zero), followed by new session reset `...-135336/start.json` and `...-135338/status.json` (vote counts reset to zero).

### Phase 4 - QA verification and evidence capture

Acceptance criteria: role-visibility behavior, countdown cadence, and counter-reset behavior are validated with reproducible evidence.

- [Done] P4.1 - Run static validation on touched PHP files.
  - `php -l <each touched plugin PHP file>`.
  - Executor result: `php -l` passed for all touched files (`MapPoolService.php`, `VetoDraftDomainTrait.php`, `VetoDraftCatalog.php`, `VetoDraftCoordinator.php`, `MatchmakingVoteSession.php`).
- [Done] P4.2 - Sync plugin runtime before behavior checks.
  - `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh`.
  - Executor result: hot-sync succeeded (sessions `pty_89366288` and final `pty_9747e337`), plugin load marker found; latest artifacts at `pixel-sm-server/logs/dev/dev-plugin-hot-sync-shootmania-20260222-140056.log` and `pixel-sm-server/logs/dev/dev-plugin-hot-sync-maniacontrol-20260222-140056.log`.
- [Done] P4.3 - Execute targeted manual/functional checks for this scope.
  - Non-admin `/pcveto maps`: no UID suffixes.
  - Admin map views: UID suffixes still visible.
  - Completion chat: only admin sees `Series order`, `Completion branch`, `Opener jump`.
  - Countdown with `duration=120`: announcements at `120,110,100,...,10,5,4,3,2,1` only.
  - `/pcveto status`: non-admin sees veto result only; admin sees operational lines.
  - New session reset: start session A, cast votes, end/cancel, start session B, confirm vote totals reset to `0`.
  - Executor evidence set:
    - deterministic role/status visibility harness assertions all passed (non-admin map/status filtering + admin visibility retention + role-aware session overview lines),
    - countdown runtime marker replay for `duration=15` captured exactly `15,10,5,4,3,2,1` once per second/session in `pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log`,
    - `duration=120` schedule generation validated by deterministic catalog probe output (`120,110,100,...,10,5,4,3,2,1`).
- [Done] P4.4 - Run non-regression scripts.
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh status`
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix`
  - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix`
  - Executor result: all required scripts exited `0` in session `pty_caee6fdf`.
- [Done] P4.5 - Archive evidence paths in QA logs.
  - Store references under `pixel-sm-server/logs/qa/<timestamp-run>/` and include key files in handoff notes.
  - Executor evidence paths captured:
    - required status replay: `pixel-sm-server/logs/qa/veto-role-visibility-countdown-reset/veto-payload-sim-20260222-135435/status.json`,
    - required veto matrix summary: `pixel-sm-server/logs/qa/veto-role-visibility-countdown-reset/veto-payload-sim-20260222-135436/summary.md`,
    - required admin matrix summary: `pixel-sm-server/logs/qa/veto-role-visibility-countdown-reset/admin-payload-sim-20260222-135454/summary.md`,
    - vote-reset deterministic replay set: `veto-payload-sim-20260222-135328/`, `...-135330/`, `...-135333/`, `...-135336/`, `...-135338/` under `pixel-sm-server/logs/qa/veto-role-visibility-countdown-reset/`,
    - countdown runtime markers: `pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log` (`session=matchmaking_vote-1771764982-i`, remaining `15,10,5,4,3,2,1`),
    - hot-sync logs: `pixel-sm-server/logs/dev/dev-plugin-hot-sync-shootmania-20260222-140056.log`, `pixel-sm-server/logs/dev/dev-plugin-hot-sync-maniacontrol-20260222-140056.log`.

### Phase 5 - Documentation/contract sync and handoff

Acceptance criteria: docs match shipped behavior, with compatibility expectations clearly stated.

- [Done] P5.1 - Update behavior docs.
  - `pixel-control-plugin/FEATURES.md`: role-based visibility for map IDs/completion lines/status output, and dynamic countdown cadence.
  - Executor result: behavior doc updated with role-scoped map/status/completion visibility and duration-derived countdown cadence.
- [Done] P5.2 - Update contract notes when behavior wording changed.
  - `pixel-control-plugin/docs/event-contract.md` and `API_CONTRACT.md`: replace fixed countdown wording (`40/30/20/10`) with duration-derived cadence note; clarify that visibility changes are control-surface/chat scoped.
  - Executor result: contract docs updated; payload compatibility for `PixelControl.VetoDraft.Status` explicitly preserved while narrowing chat visibility.
- [Done] P5.3 - Update QA checklist expectations.
  - `pixel-control-plugin/docs/veto-system-test-checklist.md`: add/adjust role-specific assertions for map-ID visibility, status output scoping, completion-line scoping, and dynamic countdown sequence.
  - Executor result: checklist updated with non-admin/admin map/status visibility assertions, admin-only completion diagnostics checks, and dynamic countdown cadence expectations.
- [Done] P5.4 - Record incident memory in local `AGENTS.md` if execution uncovers blockers/regressions (symptom, root cause, fix, validation).
  - Executor result: local memory updated in `AGENTS.md` with shipped role-visibility/status-scope/countdown/reset behavior and evidence paths; no new blocker incident occurred during execution.

## Validation strategy

- Required static checks:
  - `php -l <each touched plugin PHP file>`
- Required runtime sync:
  - `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh`
- Required functional checks:
  - Role visibility checks (`maps`, `status`, completion lines).
  - Countdown cadence check with duration sentinel (`120`).
  - Vote-counter reset check across two consecutive matchmaking sessions.
- Required scripted checks:
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh status`
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix`
  - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix`

## Risks and rollback notes

- Risks:
  - Role mapping ambiguity (`RIGHT_CONTROL` vs stricter admin level) can expose/hide data to the wrong audience.
  - Status-output narrowing may break operator habits or checks that relied on current verbose player output.
  - Dynamic countdown generation can introduce off-by-one or duplicate announcement regressions.
  - Map-ID visibility filtering can regress tournament action discoverability if player guidance is not preserved.
- Mitigations:
  - Centralize role checks in helper methods and reuse existing permission model.
  - Keep communication payload `status` shape unchanged while narrowing only chat/command text exposure.
  - Add deterministic threshold-generation tests for multiple durations and dedupe assertions.
  - Preserve index-based map selection guidance in public messages.
- Rollback path:
  - Revert visibility-scoping changes in veto chat layer if operational emergency occurs.
  - Keep feature toggle fallback available (`PIXEL_CONTROL_VETO_DRAFT_ENABLED=0`) for full veto control-surface rollback.

## Success criteria

- Normal players do not see map UIDs in veto map listings; admin audience still can.
- `Series order`, `Completion branch`, and `Opener jump` lines are admin-only.
- Matchmaking countdown follows configured-duration stepping (`N, N-10, ... , 10, 5, 4, 3, 2, 1`) with no per-session duplicates.
- `/pcveto status` for normal players is veto-result-focused and excludes operational/admin diagnostics.
- Every newly started matchmaking veto session reports vote totals reset to zero before any vote is cast.
