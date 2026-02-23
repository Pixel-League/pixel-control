# PLAN - Whitelist, admin-only vote policy, and team roster control milestone (2026-02-23)

## Context

- Purpose: implement a first production-usable control milestone in `pixel-control-plugin/` for server access control (whitelist), vote governance (admin-only), and deterministic team assignment enforcement in team modes.
- Scope:
  - Whitelist blocks server access for non-whitelisted players and is persisted in plugin settings.
  - Vote policy prevents normal players from initiating/using votes; admin-only vote flow prefers native ManiaControl behavior and has a defined fallback.
  - Team control milestone 1 adds persisted login->team roster assignment, auto-force to assigned team on spawn/connect flow, and team-switch lock behavior.
  - Team behavior applies to all team modes (no Elite-only implementation).
  - Research outcomes for dedicated/ManiaControl capabilities are verified and documented in-plan and in plugin docs.
- Background / verified findings (already confirmed):
  - Whitelist/guest-list methods exist in dedicated XML-RPC: `AddGuest`, `RemoveGuest`, `CleanGuestList`, `GetGuestList`, `SaveGuestList`, `LoadGuestList`.
  - Vote controls exist: `SetCallVoteTimeOut` (`0` disables votes), `SetCallVoteRatios`, callback `ManiaPlanet.VoteUpdated(StateName, Login, CmdName, CmdParam)`.
  - Team controls exist: `SetForcedTeams`/`GetForcedTeams`, `ForcePlayerTeam`, `SetTeamInfo` (deprecated), `GetTeamInfo` (includes name/emblem/clublink fields), `SetForcedClubLinks`/`GetForcedClubLinks`.
- Research conclusions to drive implementation:
  - There is no reliable dedicated-native toggle equivalent to "only admins may start all votes"; enforcement must be policy-driven (cancel non-admin votes on vote callback) with a strict fallback (disable callvotes globally and route admin votes through Pixel control actions).
  - Guest-list APIs provide native allow-list primitives, but join refusal behavior may still depend on server policy/runtime mode; plugin must keep a deterministic kick/refuse fallback for non-whitelisted joins.
  - Team metadata editing via `SetTeamInfo` is deprecated and out-of-scope for this milestone; use `GetTeamInfo` and forced-team APIs for enforcement only.
- Non-goals:
  - No backend (`pixel-control-server/`) implementation.
  - No edits under `ressources/`.
  - No redesign of veto/draft flows in this milestone.
  - No full team-brand management UI (name/emblem/clublink write workflows).
- Constraints / assumptions:
  - Keep `ressources/` read-only; copy patterns only.
  - Keep additive contract behavior unless explicitly required; document every externally visible change.
  - Preserve existing delegated admin action behavior while extending catalog and permissions.

## Planned file touch map

Likely existing files to edit:

- `pixel-control-plugin/src/PixelControlPlugin.php`
- `pixel-control-plugin/src/Callbacks/CallbackRegistry.php`
- `pixel-control-plugin/src/Domain/Core/CoreDomainTrait.php`
- `pixel-control-plugin/src/Domain/Admin/AdminControlDomainTrait.php`
- `pixel-control-plugin/src/Admin/AdminActionCatalog.php`
- `pixel-control-plugin/src/Admin/NativeAdminGateway.php`
- `pixel-control-plugin/src/Domain/Player/PlayerDomainTrait.php`
- `pixel-control-plugin/FEATURES.md`
- `pixel-control-plugin/docs/admin-capability-delegation.md`
- `pixel-control-plugin/docs/event-contract.md`
- `API_CONTRACT.md`

Likely new files to add:

- `pixel-control-plugin/src/AccessControl/WhitelistCatalog.php`
- `pixel-control-plugin/src/AccessControl/WhitelistStateInterface.php`
- `pixel-control-plugin/src/AccessControl/WhitelistState.php`
- `pixel-control-plugin/src/TeamControl/TeamRosterCatalog.php`
- `pixel-control-plugin/src/TeamControl/TeamRosterStateInterface.php`
- `pixel-control-plugin/src/TeamControl/TeamRosterState.php`
- `pixel-control-plugin/src/VoteControl/VotePolicyCatalog.php`
- `pixel-control-plugin/src/VoteControl/VotePolicyStateInterface.php`
- `pixel-control-plugin/src/VoteControl/VotePolicyState.php`
- `pixel-control-plugin/src/Domain/AccessControl/AccessControlDomainTrait.php`
- `pixel-control-plugin/src/Domain/TeamControl/TeamControlDomainTrait.php`
- `pixel-control-plugin/docs/audit/team-vote-whitelist-capability-audit-2026-02-23.md`
- `pixel-sm-server/scripts/qa-admin-matrix-actions/27-whitelist-enable.sh`
- `pixel-sm-server/scripts/qa-admin-matrix-actions/28-whitelist-add.sh`
- `pixel-sm-server/scripts/qa-admin-matrix-actions/29-whitelist-list.sh`
- `pixel-sm-server/scripts/qa-admin-matrix-actions/30-vote-policy-set.sh`
- `pixel-sm-server/scripts/qa-admin-matrix-actions/31-team-roster-assign.sh`
- `pixel-sm-server/scripts/automated-suite/admin-actions/whitelist-enable.sh`
- `pixel-sm-server/scripts/automated-suite/admin-actions/whitelist-add.sh`
- `pixel-sm-server/scripts/automated-suite/admin-actions/whitelist-disable.sh`
- `pixel-sm-server/scripts/automated-suite/admin-actions/whitelist-remove.sh`
- `pixel-sm-server/scripts/automated-suite/admin-actions/whitelist-list.sh`
- `pixel-sm-server/scripts/automated-suite/admin-actions/whitelist-clean.sh`
- `pixel-sm-server/scripts/automated-suite/admin-actions/whitelist-sync.sh`
- `pixel-sm-server/scripts/automated-suite/admin-actions/vote-policy-get.sh`
- `pixel-sm-server/scripts/automated-suite/admin-actions/vote-policy-set.sh`
- `pixel-sm-server/scripts/automated-suite/admin-actions/team-policy-get.sh`
- `pixel-sm-server/scripts/automated-suite/admin-actions/team-policy-set.sh`
- `pixel-sm-server/scripts/automated-suite/admin-actions/team-roster-assign.sh`
- `pixel-sm-server/scripts/automated-suite/admin-actions/team-roster-unassign.sh`
- `pixel-sm-server/scripts/automated-suite/admin-actions/team-roster-list.sh`

## Steps

- [Done] Phase 0 - Capability recon and policy decisions freeze
- [Done] Phase 1 - Implement whitelist access-control policy (persisted + enforced)
- [Done] Phase 2 - Implement admin-only vote policy with native-first fallback
- [Done] Phase 3 - Implement team roster assignment and switch-lock enforcement
- [Done] Phase 4 - Wire control surface, docs, and contract updates
- [Done] Phase 5 - Validation, evidence capture, and handoff

### Phase 0 - Capability recon and policy decisions freeze

- [Done] P0.1 - Verify capability assumptions against ManiaControl/dedicated reference APIs and callbacks.
  - Confirm callback and method signatures used by implementation decisions (`CB_MP_VOTEUPDATED`, guest-list methods, forced-team methods, team info structures).
  - Record any runtime caveats affecting strictness (for example callback timing races or mode-specific behavior).
- [Done] P0.2 - Publish an audit artifact with explicit "supported vs not supported" conclusions.
  - Create `pixel-control-plugin/docs/audit/team-vote-whitelist-capability-audit-2026-02-23.md` with a capability matrix and final policy decisions.
- [Done] P0.3 - Freeze fallback semantics before coding.
  - Vote fallback hierarchy: `cancel_non_admin_vote_on_callback` -> `disable_callvotes_and_use_admin_actions`.
  - Whitelist fallback hierarchy: `guest_list_sync` -> `connect-time kick/refuse of non-whitelisted players`.
  - Team fallback hierarchy: `team-mode enforce` -> `capability_unavailable` outside team mode.

### Phase 1 - Implement whitelist access-control policy (persisted + enforced)

- [Done] P1.1 - Add whitelist settings/constants/runtime initialization.
  - Add plugin setting keys and env overrides for whitelist enablement and persisted roster source.
  - Initialize/reset state in plugin load/unload paths.
- [Done] P1.2 - Add whitelist state module with deterministic persistence behavior.
  - Implement typed whitelist state (`WhitelistCatalog`, `WhitelistStateInterface`, `WhitelistState`) with snapshot/get/set/add/remove semantics.
  - Persist via `SettingManager` with rollback-safe behavior similar to existing series-setting persistence patterns.
- [Done] P1.3 - Extend admin action catalog and gateway for whitelist operations.
  - Add actions like `whitelist.enable`, `whitelist.disable`, `whitelist.add`, `whitelist.remove`, `whitelist.list`, `whitelist.sync`.
  - Define plugin rights and minimum auth levels in `AdminActionCatalog`.
- [Done] P1.4 - Enforce whitelist on live player flow.
  - On connect/player-info callbacks, reject or kick non-whitelisted players when whitelist is enabled.
  - Synchronize native guest list where possible (`AddGuest`/`RemoveGuest`/`SaveGuestList`/`LoadGuestList`).
  - Emit deterministic observability markers and action result codes for allow/deny decisions.
- [Done] P1.5 - Expose whitelist capability/state in `PixelControl.Admin.ListActions` and connectivity capabilities.

### Phase 2 - Implement admin-only vote policy with native-first fallback

- [Done] P2.1 - Add vote-policy configuration model and settings.
  - Introduce policy modes (for example `cancel_non_admin`, `disable_all_non_admin`) and persist selected mode in plugin settings.
- [Done] P2.2 - Register and handle `ManiaPlanet.VoteUpdated` callback.
  - Extend `CallbackRegistry` and runtime handler wiring so vote updates are observed centrally.
  - Parse vote callback payload defensively (`StateName`, `Login`, `CmdName`, `CmdParam`).
- [Done] P2.3 - Enforce admin-only vote behavior in native-first mode.
  - If vote initiator is non-admin, cancel vote immediately and record deterministic policy marker.
  - Keep admin-initiated votes untouched.
- [Done] P2.4 - Implement strict fallback when native-only-admin cannot be guaranteed.
  - Apply global vote disable (`SetCallVoteTimeOut(0)`) and rely on privileged Pixel admin actions (`vote.custom_start`, `vote.cancel`, configured vote actions).
  - Surface fallback mode explicitly in status/list payloads and docs.
- [Done] P2.5 - Add/extend admin actions for vote policy control (`vote.policy.get`, `vote.policy.set`) and permission gating.

### Phase 3 - Implement team roster assignment and switch-lock enforcement

- [Done] P3.1 - Add team roster state model (login -> team) persisted in plugin settings.
  - Implement typed state (`TeamRosterCatalog`, `TeamRosterStateInterface`, `TeamRosterState`) with assign/unassign/list/snapshot semantics.
  - Include validation for supported teams (`0|1|red|blue` aliases normalized to canonical team IDs).
- [Done] P3.2 - Add delegated admin actions for roster management and policy status.
  - Add actions such as `team.roster.assign`, `team.roster.unassign`, `team.roster.list`, `team.policy.get`, `team.policy.set`.
  - Route through native gateway + state module with deterministic error codes.
- [Done] P3.3 - Enforce assignment and team-switch lock in all team modes.
  - Enable forced-team behavior (`SetForcedTeams(true)`) when policy is active and mode is team-based.
  - On connect/info change and periodic reconciliation, force assigned players to configured team (`ForcePlayerTeam`) and immediately correct unauthorized side switches.
  - Auto-force spawn/play state when needed using existing player force actions.
- [Done] P3.4 - Add team capability visibility without deprecated write paths.
  - Expose `GetTeamInfo` (name/emblem/club link), `GetForcedTeams`, and `GetForcedClubLinks` in status payloads for observability.
  - Keep `SetTeamInfo` out of execution path (documented as deprecated/non-goal).
- [Done] P3.5 - Ensure non-team modes degrade safely.
  - Return deterministic `capability_unavailable` for team-enforcement actions outside team mode.

### Phase 4 - Wire control surface, docs, and contract updates

- [Done] P4.1 - Integrate new policy domains into plugin runtime wiring.
  - Register/init/reset new domain state and handlers in `PixelControlPlugin.php` + `CoreDomainTrait`.
  - Keep existing admin/veto/state behavior intact.
- [Done] P4.2 - Keep command/communication surfaces aligned.
  - Ensure `//pcadmin` help/action normalization includes new actions.
  - Ensure `PixelControl.Admin.ListActions` includes new definitions, policy snapshots, and fallback mode visibility.
- [Done] P4.3 - Update required documentation files in same change set.
  - `pixel-control-plugin/FEATURES.md`
  - `pixel-control-plugin/docs/admin-capability-delegation.md`
  - `pixel-control-plugin/docs/event-contract.md`
  - `API_CONTRACT.md`
- [Done] P4.4 - Extend QA action descriptor coverage for new admin actions.
  - Add modular matrix step scripts under `pixel-sm-server/scripts/qa-admin-matrix-actions/`.
  - Add modular automated-suite descriptors under `pixel-sm-server/scripts/automated-suite/admin-actions/`.

### Phase 5 - Validation, evidence capture, and handoff

- [Done] P5.1 - Run required PHP lint on all touched plugin PHP files.
  - Minimum required: `php -l` for each touched file under `pixel-control-plugin/src/`.
- [Done] P5.2 - Hot-sync plugin runtime before reporting results.
  - Required command: `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh`.
- [Done] P5.3 - Run communication/admin QA regression with new policy actions.
  - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix`
  - Targeted `execute` calls for whitelist/vote-policy/team-roster actions and status checks.
- [Done] P5.4 - Validate remaining runtime behavior with split autonomous/manual closure.
  - [Done] P5.4.a - Autonomous validation now (container + payload/fake-player compatible).
    - Re-run targeted vote-policy scenario and confirm non-admin vote initiation is blocked/canceled with deterministic result codes/markers.
    - Run whitelist/team assignment scenarios with fake players to confirm guard semantics are explicit (`isFakePlayer` short-circuit) and no false "human enforcement" claims are recorded.
    - Expected artifacts:
      - `pixel-sm-server/logs/qa/team-vote-whitelist-<timestamp>/p5.4-autonomous/summary.md`
      - `pixel-sm-server/logs/qa/team-vote-whitelist-<timestamp>/p5.4-autonomous/vote-policy-non-admin.json`
      - `pixel-sm-server/logs/qa/team-vote-whitelist-<timestamp>/p5.4-autonomous/fake-player-guard-observability.log`
    - Captured evidence set: `pixel-sm-server/logs/qa/team-vote-whitelist-20260223-145834/p5.4-autonomous/`.
    - Runtime note: dedicated fake-player vote probe currently reports empty vote initiator login (`callerLogin=""`), so callback-level non-admin cancel verification remains manual-only and is intentionally not over-claimed in autonomous evidence.
  - [Done] P5.4.b - Manual real-client validation (required to close P5.4, user-validated 2026-02-23).
    - Validate whitelist deny path with a real non-whitelisted login (refused or kicked) and a whitelisted control login (allowed).
    - Validate non-admin vote initiation block with a real non-admin account under selected policy mode.
    - Validate team roster assignment + switch lock in Elite and one additional team mode (Joust/Siege/Battle based on runtime availability) with real players.
    - Expected artifacts:
      - `pixel-sm-server/logs/manual/team-vote-whitelist-<timestamp>/MANUAL-TEST-MATRIX.md`
      - `pixel-sm-server/logs/manual/team-vote-whitelist-<timestamp>/SESSION-<id>-notes.md`
      - `pixel-sm-server/logs/manual/team-vote-whitelist-<timestamp>/SESSION-<id>-payload.ndjson`
      - `pixel-sm-server/logs/manual/team-vote-whitelist-<timestamp>/SESSION-<id>-evidence.md`
      - `pixel-sm-server/logs/manual/team-vote-whitelist-<timestamp>/INDEX.md`
    - Manual scaffolding prepared at `pixel-sm-server/logs/manual/team-vote-whitelist-20260223-145834/`; real-client execution evidence is still pending.
- [Done] P5.5 - Capture evidence index and handoff notes.
  - Store artifacts under `pixel-sm-server/logs/qa/<timestamped-run>/` and reference key files in executor handoff summary.

## Risks and fallback behavior

- Vote callback timing race may allow brief vote start window before cancellation.
  - Mitigation: expose strict fallback mode that disables callvotes globally and routes vote initiation through admin-only Pixel actions.
- Guest-list enforcement may vary by runtime server policy.
  - Mitigation: combine native guest-list sync with deterministic connect-time deny/kick enforcement.
- Team-force operations can fail transiently (`UnknownPlayer`/transition races) during connect/map transitions.
  - Mitigation: add bounded retry/reconciliation on subsequent player/lifecycle ticks and keep deterministic warning markers.
- Non-team mode execution can create noisy failures.
  - Mitigation: mode guards + explicit `capability_unavailable` responses.

## Acceptance criteria (major scope)

- Whitelist:
  - When whitelist is enabled, non-whitelisted players are denied server access (refused or kicked) and whitelisted players are allowed.
  - Whitelist state survives plugin reload/restart via plugin settings.
- Vote policy:
  - Non-admin players cannot successfully initiate/keep votes under configured policy.
  - Admin vote control remains available through native-first flow, with strict fallback mode documented and functional.
- Team control milestone 1:
  - Persisted login->team assignments are configurable via admin control surface.
  - Assigned players are auto-forced to correct team and unauthorized team switches are corrected/blocked.
- Team-mode coverage:
  - Enforcement applies to all team modes through mode-detection guards; non-team modes return deterministic capability responses.
- Research and docs alignment:
  - Capability audit document exists with conclusions and limitations.
  - `FEATURES.md`, `docs/admin-capability-delegation.md`, `docs/event-contract.md`, and `API_CONTRACT.md` are synchronized with implemented behavior.
- Validation baseline:
  - PHP lint passes for touched plugin files.
  - `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh` is executed.
  - QA/admin simulation evidence captures new action behavior and non-regression status.

## Evidence / Artifacts

- Capability audit: `pixel-control-plugin/docs/audit/team-vote-whitelist-capability-audit-2026-02-23.md`
- QA evidence root (planned): `pixel-sm-server/logs/qa/team-vote-whitelist-<timestamp>/`
- Remaining P5.4 autonomous evidence (planned): `pixel-sm-server/logs/qa/team-vote-whitelist-<timestamp>/p5.4-autonomous/`
- Remaining P5.4 manual evidence (planned): `pixel-sm-server/logs/manual/team-vote-whitelist-<timestamp>/`
- Admin matrix artifacts: `pixel-sm-server/logs/qa/admin-payload-sim-<timestamp>/`
- Captured admin matrix evidence (latest pass): `pixel-sm-server/logs/qa/team-vote-whitelist-20260223-144310/admin-payload-sim-20260223-144311/summary.md`
- Captured targeted execute evidence: `pixel-sm-server/logs/qa/team-vote-whitelist-20260223-144407/targeted/`
- Captured hot-sync logs: `pixel-sm-server/logs/dev/dev-plugin-hot-sync-shootmania-20260223-144233.log`, `pixel-sm-server/logs/dev/dev-plugin-hot-sync-maniacontrol-20260223-144233.log`
