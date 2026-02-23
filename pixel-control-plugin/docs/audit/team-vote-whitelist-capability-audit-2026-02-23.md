# Team, Vote, and Whitelist Capability Audit (2026-02-23)

## Scope

- Target plugin scope: `pixel-control-plugin` milestone for whitelist, admin-only vote governance, and team roster controls.
- Evidence source: `ressources/ManiaControl/**` reference runtime/library code (read-only).
- Goal: freeze supported/unsupported capabilities and deterministic fallback policy before implementation.

## Verified native capability evidence

### Whitelist / guest list primitives

- Dedicated API guest-list methods are available through ManiaControl dedicated client wrapper:
  - `addGuest($player)`
  - `removeGuest($player)`
  - `cleanGuestList()`
  - `getGuestList($length, $offset)`
  - `loadGuestList($filename = '')`
  - `saveGuestList($filename = '')`
- Evidence location:
  - `ressources/ManiaControl/libs/Maniaplanet/DedicatedServer/Connection.php:986`
  - `ressources/ManiaControl/libs/Maniaplanet/DedicatedServer/Connection.php:1003`
  - `ressources/ManiaControl/libs/Maniaplanet/DedicatedServer/Connection.php:1018`
  - `ressources/ManiaControl/libs/Maniaplanet/DedicatedServer/Connection.php:1031`
  - `ressources/ManiaControl/libs/Maniaplanet/DedicatedServer/Connection.php:1051`
  - `ressources/ManiaControl/libs/Maniaplanet/DedicatedServer/Connection.php:1068`

### Vote primitives and callback

- Dedicated API vote-control methods are available:
  - `cancelVote()`
  - `setCallVoteTimeOut($timeout)` (doc notes: `0` disables votes)
  - `setCallVoteRatios($ratios, $replaceAll = true)`
  - `getCallVoteRatios()`
  - `getCallVoteTimeOut()`
- Evidence location:
  - `ressources/ManiaControl/libs/Maniaplanet/DedicatedServer/Connection.php:374`
  - `ressources/ManiaControl/libs/Maniaplanet/DedicatedServer/Connection.php:399`
  - `ressources/ManiaControl/libs/Maniaplanet/DedicatedServer/Connection.php:452`
  - `ressources/ManiaControl/libs/Maniaplanet/DedicatedServer/Connection.php:482`
  - `ressources/ManiaControl/libs/Maniaplanet/DedicatedServer/Connection.php:412`
- ManiaPlanet vote callback constant exists in callback manager:
  - `CallbackManager::CB_MP_VOTEUPDATED = 'ManiaPlanet.VoteUpdated'`
- Evidence location:
  - `ressources/ManiaControl/core/Callbacks/CallbackManager.php:45`

### Team control primitives

- Dedicated team-force primitives are available:
  - `setForcedTeams($enable)` / `getForcedTeams()`
  - `forcePlayerTeam($player, $team)`
- Team metadata visibility/write primitives:
  - `getTeamInfo($team)`
  - `setTeamInfo(...)` (deprecated)
  - `setForcedClubLinks($team1, $team2)` / `getForcedClubLinks()`
- Evidence location:
  - `ressources/ManiaControl/libs/Maniaplanet/DedicatedServer/Connection.php:2236`
  - `ressources/ManiaControl/libs/Maniaplanet/DedicatedServer/Connection.php:2249`
  - `ressources/ManiaControl/libs/Maniaplanet/DedicatedServer/Connection.php:4006`
  - `ressources/ManiaControl/libs/Maniaplanet/DedicatedServer/Connection.php:1884`
  - `ressources/ManiaControl/libs/Maniaplanet/DedicatedServer/Connection.php:1858`
  - `ressources/ManiaControl/libs/Maniaplanet/DedicatedServer/Connection.php:1903`
  - `ressources/ManiaControl/libs/Maniaplanet/DedicatedServer/Connection.php:1918`

## Supported vs unsupported matrix

| Capability | Supported in milestone | Notes |
| --- | --- | --- |
| Persist plugin-side whitelist roster + enable flag | Yes | Stored via plugin settings; runtime state owner in plugin domain layer. |
| Sync whitelist roster to native guest list | Yes | Uses `cleanGuestList + addGuest + saveGuestList` flow. |
| Deterministic deny fallback for non-whitelisted joins | Yes | Plugin enforces connect-time kick/refuse path when policy enabled. |
| Hard native "only admins may start votes" toggle | No (native gap) | No reliable dedicated primitive found for universal only-admin vote creation. |
| Admin-only vote policy (native-first) | Yes | Enforced by observing `ManiaPlanet.VoteUpdated` and canceling non-admin votes. |
| Strict vote fallback mode | Yes | `setCallVoteTimeOut(0)` + Pixel delegated admin vote actions path. |
| Persisted login->team roster assignment | Yes | Stored in plugin settings and enforced at runtime in team modes. |
| Disable manual team switch where possible | Yes (best-effort) | Uses `setForcedTeams(true)` + enforcement reconciliation. |
| Edit team names/colors/emblems via plugin | No (non-goal) | `setTeamInfo` is deprecated and excluded. |
| Team enforcement outside team modes | No (by design) | Returns deterministic `capability_unavailable` outside team-mode scripts. |

## Runtime caveats and strictness limits

- Vote callback timing can expose a short race where a non-admin vote appears before cancellation; strict mode exists to remove this risk by globally disabling callvotes.
- Guest-list behavior can be server-policy dependent in some runtimes; plugin keeps connect-time deny fallback to preserve deterministic access control.
- Team force operations can transiently fail during joins/map transitions (`UnknownPlayer`, change-in-progress windows); enforcement must use bounded reconciliation retries.
- `setForcedTeams(true)` reduces manual side changes but may still depend on mode/script behavior; plugin must keep correction loop for assigned players.

## Frozen policy decisions for implementation

- Vote policy hierarchy:
  1. `cancel_non_admin_vote_on_callback` (native-first default)
  2. `disable_callvotes_and_use_admin_actions` (strict fallback)
- Whitelist hierarchy:
  1. plugin persisted roster state
  2. native guest-list synchronization
  3. connect-time deny kick fallback for non-whitelisted players
- Team hierarchy:
  1. enforce only in team modes
  2. use forced teams + roster reconciliation
  3. return `capability_unavailable` outside team modes

## Implementation boundary reminders

- Keep `ressources/` immutable/read-only.
- Keep control surface additive under existing delegated admin architecture:
  - `AdminActionCatalog`
  - `NativeAdminGateway`
  - domain traits + settings-backed policy state
- Keep communication/list visibility deterministic for new policy snapshots and capability flags.
