# Pixel Control Native Admin Capability Delegation

This document defines the delegated admin-control routing boundary for `pixel-control-plugin`.

## Ownership boundary

- Pixel plugin owns:
  - telemetry normalization and envelope identity,
  - queue/retry/outage transport behavior,
  - contract/version signaling and capability exposure.
- ManiaControl native services own:
  - permission enforcement and auth hierarchy,
  - map/match-flow command execution,
  - player force and auth grant/revoke execution,
  - vote cancellation/ratio mutation and optional custom-vote execution,
  - team-mode runtime guards (`modeIsTeamMode`) and forced-team primitives (`setForcedTeams`, `forcePlayerTeam`).
- Pixel plugin state modules own:
  - whitelist registry persistence and guest-list sync orchestration,
  - vote policy mode state (`cancel_non_admin_vote_on_callback` vs strict fallback),
  - login->team roster assignments and team policy persistence (`policy_enabled`, `switch_lock_enabled`).

## Control entry points

- Admin chat command: `//pcadmin` (plus configured alias from `PIXEL_CONTROL_ADMIN_COMMAND`).
- Communication methods:
  - `PixelControl.Admin.ExecuteAction`
  - `PixelControl.Admin.ListActions`

Feature toggle and runtime controls:

- `Pixel Control Native Admin Control Enabled` / `PIXEL_CONTROL_ADMIN_CONTROL_ENABLED`
- `Pixel Control Native Admin Command` / `PIXEL_CONTROL_ADMIN_COMMAND`
- `Pixel Control Pause State Max Age Seconds` / `PIXEL_CONTROL_ADMIN_PAUSE_STATE_MAX_AGE_SECONDS`

## Permission model

- Chat-command path (`//pcadmin`) is actor-bound:
  - plugin rights declared through `AuthenticationManager::definePluginPermissionLevel(...)`,
  - requests gated with `AuthenticationManager::checkPluginPermission(...)`.
- Communication payload path (`PixelControl.Admin.ExecuteAction`) is currently trusted/unauthenticated:
  - `actor_login` is optional,
  - plugin permission checks are skipped by design,
  - this is temporary until signed/authenticated payload verification is introduced.
- Effective minimum rights for chat-command path:
  - Moderator: map skip/restart/jump/queue, warmup/pause, vote-cancel, player-force, custom-vote-start, whitelist add/remove/list, vote policy get, team roster assign/unassign/list, team policy get
  - Admin: map.add/map.remove, vote-ratio, auth-grant/auth-revoke, whitelist enable/disable/clean/sync, vote policy set, team policy set

## Action routing matrix

| Action | Native entrypoint | Notes |
| --- | --- | --- |
| `map.skip` | `MapActions::skipMap` | delegate |
| `map.restart` | `MapActions::restartMap` | delegate |
| `map.jump` | `MapActions::skipToMapByUid` or `MapActions::skipToMapByMxId` | `map_uid` or `mx_id` |
| `map.queue` | `MapQueue::serverAddMapToMapQueue` | resolves `mx_id` to map uid |
| `map.add` | `MapManager::addMapFromMx` | requires `mx_id`; async MX import |
| `map.remove` | `MapManager::removeMap` | accepts `map_uid` or `mx_id` (resolved to uid); optional `erase_map_file` |
| `warmup.extend` | `ModeScriptEventManager::extendManiaPlanetWarmup` | script-mode guard |
| `warmup.end` | `ModeScriptEventManager::stopManiaPlanetWarmup` | script-mode guard |
| `pause.start` | `ModeScriptEventManager::startPause` | requires `modeUsesPause()` |
| `pause.end` | `ModeScriptEventManager::endPause` | requires `modeUsesPause()` |
| `vote.cancel` | `Client::cancelVote` | native failure when no vote running |
| `vote.set_ratio` | `Client::setCallVoteRatios` | validates command + ratio range |
| `vote.policy.get` | `VotePolicyState::getSnapshot` | plugin-state snapshot exposure |
| `vote.policy.set` | `VotePolicyState::setMode` | persists selected vote governance mode |
| `player.force_team` | `PlayerActions::forcePlayerToTeam` | team-mode guard; actorless payload path uses native `calledByAdmin=false` |
| `player.force_play` | `PlayerActions::forcePlayerToPlay` | actorless payload path uses native `calledByAdmin=false` |
| `player.force_spec` | `PlayerActions::forcePlayerToSpectator` | actorless payload path uses native `calledByAdmin=false` |
| `auth.grant` | `PlayerActions::grantAuthLevel` or `AuthenticationManager::grantAuthLevel` | actor-bound chat path keeps native hierarchy checks; actorless payload path uses AuthenticationManager direct grant |
| `auth.revoke` | `PlayerActions::revokeAuthLevel` or `AuthenticationManager::grantAuthLevel(AUTH_LEVEL_PLAYER)` | actor-bound chat path keeps native hierarchy checks; actorless payload path applies direct fallback-to-player level |
| `vote.custom_start` | `MCTeam\CustomVotesPlugin::startVote` | capability unavailable when plugin inactive; actorless payload path uses fallback connected initiator player |
| `whitelist.enable` | `WhitelistState::setEnabled(true)` + guest sync | plugin persists and syncs dedicated guest list |
| `whitelist.disable` | `WhitelistState::setEnabled(false)` + guest sync | plugin persists and syncs dedicated guest list |
| `whitelist.add` | `WhitelistState::addLogin` + guest sync | canonical lowercase login registry |
| `whitelist.remove` | `WhitelistState::removeLogin` + guest sync | canonical lowercase login registry |
| `whitelist.list` | `WhitelistState::getSnapshot` | plugin-state snapshot exposure |
| `whitelist.clean` | `WhitelistState::clean` + guest sync | clears persisted whitelist registry |
| `whitelist.sync` | `AccessControlDomain::syncWhitelistGuestList` | forced re-sync of native guest list from plugin snapshot |
| `team.policy.get` | `TeamRosterState::getSnapshot` | plugin-state policy + assignment snapshot |
| `team.policy.set` | `TeamRosterState::setPolicy` + runtime apply | persists `policy_enabled`/`switch_lock_enabled` |
| `team.roster.assign` | `TeamRosterState::assign` + runtime reconcile | stores login->team mapping (`0|blue|team_a`, `1|red|team_b`) |
| `team.roster.unassign` | `TeamRosterState::unassign` + runtime reconcile | removes login assignment |
| `team.roster.list` | `TeamRosterState::getSnapshot` | plugin-state assignment snapshot exposure |

## Capability and fallback semantics

- Mode-sensitive guards are explicit:
  - script-only actions return `unsupported_mode` when script mode is unavailable,
  - pause actions return `capability_unavailable` when pause is not supported in current mode,
  - team-force action returns `capability_unavailable` when mode is not team-based.
- Team-roster runtime enforcement is mode-scoped:
  - assignment/policy mutation actions remain configurable in any mode,
  - runtime forced-team apply/reconcile paths return or emit `capability_unavailable` when current mode is not team-based.
- Vote governance fallback is explicit:
  - native-first mode cancels non-admin votes on `ManiaPlanet.VoteUpdated`,
  - strict mode sets global callvote timeout to `0` and relies on privileged admin actions for vote operations.
- Native rejections and exceptions are normalized to deterministic result codes/messages.

## Rollout and rollback

Rollout defaults and safety:

- Feature is disabled by default unless explicitly enabled (`PIXEL_CONTROL_ADMIN_CONTROL_ENABLED=1` or setting `Pixel Control Native Admin Control Enabled=1`).
- When disabled, plugin remains telemetry/transport-only and does not register admin command/communication entry points.
- Startup marker confirms effective state: `[PixelControl][admin][bootstrap] enabled=yes|no, ...`.
- Current security trade-off: communication payload mode is intentionally unauthenticated (`security_mode=payload_untrusted`) and should be network-restricted until signed/authenticated server payloads are implemented.

Rollback procedure (telemetry-only fallback):

1. Set `Pixel Control Native Admin Control Enabled=0` (or unset/zero `PIXEL_CONTROL_ADMIN_CONTROL_ENABLED`).
2. Reload plugin/runtime (for dev stack: `bash pixel-sm-server/scripts/dev-plugin-sync.sh`).
3. Verify bootstrap log shows `enabled=no`.
4. Verify communication/list action calls return no listener response (or command path reports control surface disabled), while connectivity/telemetry envelopes continue.

Restore delegated controls:

1. Set `Pixel Control Native Admin Control Enabled=1`.
2. Reload plugin/runtime.
3. Verify bootstrap log shows `enabled=yes` and communication action list is available again.

## Observability

- Delegated control logs:
  - `[PixelControl][admin][action_requested]`
  - `[PixelControl][admin][action_success]`
  - `[PixelControl][admin][action_failed]`
  - `[PixelControl][admin][security_mode]` (when actorless unauthenticated payload execution is used)
- Connectivity registration capability payload exposes delegated control surface under `payload.capabilities.admin_control`.

## Validation evidence (2026-02-20)

- Delegated communication matrix: `pixel-sm-server/logs/qa/admin-delegation-20260220-1926/communication-action-matrix.json`
- Permission deny/allow matrix: `pixel-sm-server/logs/qa/admin-delegation-20260220-1926/permission-matrix.json`
- Non-Elite capability fallback matrix (Joust): `pixel-sm-server/logs/qa/admin-delegation-20260220-1926/joust-capability-matrix.json`
- Admin action log markers: `pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log`
