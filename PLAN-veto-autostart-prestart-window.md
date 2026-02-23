# PLAN - Add 15-second pre-start countdown for matchmaking veto autostart (2026-02-23)

## Context

- Purpose: implement the requested UX/flow where matchmaking veto does not start immediately when autostart conditions are met; instead, announce a 15-second pre-start window, then launch the veto session automatically.
- Scope: `pixel-control-plugin/` veto-domain autostart orchestration and related QA/docs updates; no backend runtime work in `pixel-control-server/`.
- Background / findings:
  - Current autostart path (`evaluateMatchmakingAutostartThreshold`) starts matchmaking immediately through `startConfiguredMatchmakingSession('timer_threshold', ...)` once threshold and ready gate are satisfied.
  - Session start already broadcasts map list and vote instructions via `broadcastVetoDraftSessionOverview()` from `startMatchmakingSessionWithReadyGate`.
  - Existing countdown behavior is in-session only; there is no pre-start countdown/notice before a session is created.
  - Repository may be dirty during execution; implementation must avoid reverting unrelated user changes.
- Goals:
  - Add deterministic 15-second pre-start notice for threshold-based autostart.
  - Add cancellation behavior when conditions drop during the waiting window.
  - Keep manual start flows compatible unless a narrow compatibility fix is strictly required.
- Non-goals:
  - No redesign of matchmaking/tournament veto algorithms.
  - No changes to first-party backend/API implementation.
  - No mutable operations in `ressources/`.
- Constraints / assumptions:
  - Plugin-first architecture and existing control surfaces remain authoritative (`//pcveto`, `PixelControl.VetoDraft.*`).
  - New pre-start state is runtime-ephemeral (not persisted across restart).
  - Chat messages remain English and consistently prefixed (existing `[PixelControl]` style).

## Steps

Execution rule: this is a planning artifact only. Executor keeps one active `[In progress]` item and updates statuses live.

- [Done] Phase 0 - Freeze pre-start behavioral contract and state boundaries
- [Done] Phase 1 - Implement 15-second pre-start window in autostart path
- [Done] Phase 2 - Add cancellation/re-arm handling when conditions change during waiting
- [Done] Phase 3 - Validate behavior and run required regression commands
- [Done] Phase 4 - Sync docs/contracts and incident memory

### Phase 0 - Freeze pre-start behavioral contract and state boundaries

Acceptance criteria: executor has an explicit, deterministic contract for when the pre-start window arms, starts, cancels, and re-arms.

- [Done] P0.1 - Define pre-start arm/start semantics for threshold autostart.
  - Arm exactly when matchmaking-ready + autostart-threshold conditions become satisfied in idle state.
  - Emit a start notice once per armed cycle: `[PixelControl] Matchmaking veto starts in 15s.`
  - After 15 seconds, start session automatically only if the same start conditions are still satisfied.
- [Done] P0.2 - Define explicit cancellation semantics.
  - Cancel pending start if threshold/ready conditions become invalid before countdown completion.
  - Add deterministic observability markers and (if enabled by existing style) a concise cancellation chat line.
  - Re-arm only on a new valid transition to avoid repeated spam/loops.
- [Done] P0.3 - Confirm compatibility boundaries.
  - Keep manual/chat/payload-triggered starts behaviorally unchanged (no forced 15-second delay on manual starts).
  - Keep existing session-start map overview flow unchanged so map list + vote instructions still appear when session starts.

### Phase 1 - Implement 15-second pre-start window in autostart path

Acceptance criteria: threshold autostart enters a deterministic waiting state and launches exactly once after 15 seconds when conditions remain valid.

- [Done] P1.1 - Add runtime-ephemeral pre-start state fields and reset hooks.
  - Touchpoint: `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php`.
  - Add state for pending autostart (armed timestamp/deadline, reason/source, announcement dedupe, and cancellation marker).
  - Reset pending state on plugin unload, successful session start, and other lifecycle boundaries where stale pending data is unsafe.
- [Done] P1.2 - Add timer-tick evaluation for pending autostart windows.
  - Reuse existing periodic veto timer flow to evaluate whether a pending window should continue waiting, cancel, or start.
  - Use deterministic time comparison (epoch/deadline based) rather than drift-prone decrement logic.
- [Done] P1.3 - Refactor threshold evaluator to arm pending start instead of immediate start.
  - Update `evaluateMatchmakingAutostartThreshold` to create the 15-second pending window when conditions are newly met.
  - Preserve anti-loop protections so repeated checks do not re-arm or duplicate announcement spam while already pending.

### Phase 2 - Add cancellation/re-arm handling when conditions change during waiting

Acceptance criteria: pending autostart cancels cleanly on invalid conditions and re-arms only on valid transitions.

- [Done] P2.1 - Implement condition re-validation before launch.
  - Right before launching after the 15-second window, re-check readiness/threshold/session-idle guards.
  - Abort launch if any guard fails and mark cancellation reason for observability.
- [Done] P2.2 - Implement below-threshold and ready-gate drop cancellation path.
  - When conditions drop during waiting, clear pending state and set transition state for future re-arm.
  - Ensure no hidden launch occurs after cancellation due to stale deadlines.
- [Done] P2.3 - Verify manual path compatibility in code flow.
  - Confirm vote bootstrap/manual start paths still start as before (unless explicitly routed through new delay by design).
  - Ensure active-session and post-cycle behavior does not auto-rearm without explicit ready/threshold transitions.

### Phase 3 - Validate behavior and run required regression commands

Acceptance criteria: required checks pass, and new pre-start/cancellation behavior is verifiable with deterministic evidence.

- [Done] P3.1 - Run syntax checks on touched plugin files.
  - Command: `php -l <each touched plugin PHP file>`.
- [Done] P3.2 - Sync runtime plugin after edits.
  - Command: `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh`.
- [Done] P3.3 - Run veto matrix non-regression.
  - Command: `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix`.
- [Done] P3.4 - Run admin matrix non-regression.
  - Command: `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix`.
- [Done] P3.5 - Execute targeted behavior checks for this feature.
  - Threshold satisfied -> one pre-start notice appears -> veto starts automatically after ~15 seconds.
  - Threshold/ready becomes invalid during waiting -> pending start cancels and veto does not start.
  - Conditions become valid again later -> new 15-second notice and delayed start window can arm again.

### Phase 4 - Sync docs/contracts and incident memory

Acceptance criteria: behavior changes are documented and durable operational memory is updated when needed.

- [Done] P4.1 - Update user-facing plugin docs for pre-start autostart behavior.
  - `pixel-control-plugin/FEATURES.md` and `pixel-control-plugin/docs/veto-system-test-checklist.md`.
  - Include pre-start delay semantics and cancellation expectations.
- [Done] P4.2 - Update contract docs only if payload surface changes.
  - `pixel-control-plugin/docs/event-contract.md` and `API_CONTRACT.md` if new additive fields/markers become externally observable.
- [Done] P4.3 - Append concise incident memory in local `AGENTS.md` if execution uncovers blockers.
  - Record symptom, root cause, fix, and validation signal.

## Validation commands (required)

- `php -l <each touched plugin PHP file>`
- `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh`
- `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix`
- `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix`

## Success criteria

- When autostart conditions are met, chat announces veto start in 15 seconds before any session starts.
- After the 15-second window, veto starts automatically only if autostart conditions are still valid.
- If conditions become invalid during waiting, pending start is canceled and no stale launch occurs.
- Manual start paths remain compatible, and start-time map/vote instructions still appear when the session actually starts.
- Required QA and matrix commands complete successfully with evidence captured in first-party QA log paths.
