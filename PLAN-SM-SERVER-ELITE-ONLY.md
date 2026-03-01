# PLAN - Specialize pixel-sm-server for Elite-only (2026-03-01)

## Context

- Purpose: Remove all non-Elite mode logic and configuration from `pixel-sm-server/`. This Docker dev stack should be specialized exclusively for ShootMania Elite servers. Other game modes (Joust, Battle, Siege, etc.) will get their own specialized stacks later.
- Scope: Only `pixel-sm-server/` files. Do NOT touch `pixel-control-plugin/` or `pixel-control-server/`.
- Background: The stack currently supports five mode presets (elite, siege, battle, joust, custom) via `.env.{mode}` profile files, a mode-switching script (`dev-mode-compose.sh`), multi-mode branching in `bootstrap.sh`, matchsettings templates for all modes, map directories for all modes, a multi-mode launch matrix validator, and multi-mode support in the automated test suite.
- Goals:
  - Remove all non-Elite `.env.{mode}` profile files (`.env.joust`, keep `.env.elite`).
  - Remove or simplify `scripts/dev-mode-compose.sh` (no longer needed for single mode).
  - Simplify `scripts/bootstrap.sh` by removing non-Elite preset logic (siege, battle, joust, custom, royal branches).
  - Remove non-Elite matchsettings templates (`siege.txt`, `battle.txt`, `joust.txt`, `custom.txt`).
  - Remove non-Elite map directories (`maps/battle/`, `maps/siege/`, `maps/joust/`).
  - Remove `scripts/validate-mode-launch-matrix.sh` and its deprecated wrapper `scripts/qa-mode-smoke.sh`.
  - Simplify `scripts/test-automated-suite.sh` to Elite-only (remove joust from default modes, remove mode profile switching logic).
  - Hardcode Elite values in `.env.example` and `.env.production.example`, remove mode-selection comments.
  - Update `README.md`, `SERVER-VALIDATION-CHECKLIST.md`, and `maps/README.md` to reflect Elite-only.
  - Update root `CLAUDE.md` to remove multi-mode references for `pixel-sm-server/`.
  - Stack must remain fully functional for Elite mode after all changes.
- Non-goals:
  - Do NOT delete gitignored local files (TitlePack `.gbx` files in `TitlePacks/`, game binaries in `runtime/server/`).
  - Do NOT modify `pixel-control-plugin/` or `pixel-control-server/`.
  - Do NOT remove `scripts/fetch-titlepack.sh` (useful utility, mode-agnostic).
- Constraints:
  - `.env` is gitignored (local config) -- focus on `.env.example` and `.env.production.example`.
  - `.env.elite` and `.env.joust` are also gitignored (by `.env.*` pattern), but are tracked files that exist in the working tree -- delete them.
  - `.env.production.local` is gitignored -- it is a local-only file, do not delete it.
  - Keep `scripts/fetch-titlepack.sh` as-is (generic utility).
  - `docker-compose.yml`, `docker-compose.production.yml`, `docker-compose.host.yml` have minimal mode-specific content -- mostly just env pass-through. Clean up any mode-specific comments.
  - Preserve `Dockerfile` as-is (no mode-specific logic in it).

## Inventory of files to change

### Files to DELETE

| File | Reason |
|---|---|
| `pixel-sm-server/.env.elite` | Mode profile file; no longer needed when Elite is the only mode. |
| `pixel-sm-server/.env.joust` | Non-Elite mode profile. |
| `pixel-sm-server/scripts/dev-mode-compose.sh` | Mode-switching script; no longer needed. |
| `pixel-sm-server/scripts/validate-mode-launch-matrix.sh` | Multi-mode launch matrix; not needed for single mode. |
| `pixel-sm-server/scripts/qa-mode-smoke.sh` | Deprecated wrapper for `validate-mode-launch-matrix.sh`. |
| `pixel-sm-server/templates/matchsettings/siege.txt` | Non-Elite matchsettings template. |
| `pixel-sm-server/templates/matchsettings/battle.txt` | Non-Elite matchsettings template. |
| `pixel-sm-server/templates/matchsettings/joust.txt` | Non-Elite matchsettings template. |
| `pixel-sm-server/templates/matchsettings/custom.txt` | Non-Elite matchsettings template. |
| `pixel-sm-server/maps/battle/` (entire directory) | Non-Elite map pool. |
| `pixel-sm-server/maps/siege/` (entire directory) | Non-Elite map pool. |
| `pixel-sm-server/maps/joust/` (entire directory) | Non-Elite map pool. |

### Files to EDIT

| File | What changes |
|---|---|
| `pixel-sm-server/.env.example` | Hardcode Elite values for `PIXEL_SM_TITLE_PACK`, `PIXEL_SM_MODE`. Remove mode preset comment listing siege/battle/joust/custom. Remove `PIXEL_SM_MATCHSETTINGS` flexibility comment. |
| `pixel-sm-server/.env.production.example` | Same hardcoding as `.env.example`. Remove mode selection comment block. |
| `pixel-sm-server/scripts/bootstrap.sh` | Simplify `resolve_mode_preset_name()` to only handle `elite`. Simplify `resolve_expected_title_pack_for_preset()` to only return `SMStormElite@nadeolabs`. Simplify `resolve_expected_script_for_preset()` to only return the Elite script. Simplify `auto_injection_supported_for_matchsettings()` to always return 0 (Elite supports auto-injection). Remove non-Elite branches from `validate_matchsettings_mode_script()` (the battle/siege/joust title-pack pattern matching blocks). Keep the generic file-based validation path. |
| `pixel-sm-server/docker-compose.yml` | Remove the `PIXEL_SM_MODE` environment variable pass-through (no longer needed -- bootstrap can hardcode). Keep `PIXEL_SM_MATCHSETTINGS` for override flexibility. |
| `pixel-sm-server/scripts/test-automated-suite.sh` | Change `DEFAULT_MODES_CSV` from `"elite,joust"` to `"elite"`. Remove references to `DEV_MODE_SCRIPT` / `run_mode_profile_apply()` that depend on `dev-mode-compose.sh`. Remove or simplify `MODE_MATRIX_VALIDATION_SCRIPT` references. Update usage text. |
| `pixel-sm-server/README.md` | Remove multi-mode sections: mode profile launch/relaunch section, mode preset table, mode launch matrix references, Battle titlepack provisioning step, non-Elite troubleshooting. Reframe as Elite-only stack. |
| `pixel-sm-server/SERVER-VALIDATION-CHECKLIST.md` | Remove `validate-mode-launch-matrix.sh` from checklist. Remove multi-mode references. |
| `pixel-sm-server/maps/README.md` | Remove Siege, Battle, Joust map entries and runtime notes. Keep only Elite maps. |
| `CLAUDE.md` (repo root) | Remove `scripts/dev-mode-compose.sh` from common commands. Update description to note Elite-only for `pixel-sm-server/`. Remove multi-mode convention bullet if present. |

## Steps

- [Done] Phase 1 - Delete non-Elite files
- [Done] Phase 2 - Simplify environment templates
- [Done] Phase 3 - Simplify bootstrap.sh
- [Done] Phase 4 - Simplify Docker and Compose config
- [Done] Phase 5 - Simplify test-automated-suite.sh
- [Done] Phase 6 - Update documentation
- [Done] Phase 7 - QA / verification

### Phase 1 - Delete non-Elite files

- [In progress] P1.1 - Delete non-Elite `.env.{mode}` profile files
  - Delete `pixel-sm-server/.env.elite` (mode profiles are no longer needed; Elite is the only mode).
  - Delete `pixel-sm-server/.env.joust`.
- [Todo] P1.2 - Delete mode-switching script
  - Delete `pixel-sm-server/scripts/dev-mode-compose.sh`.
- [Todo] P1.3 - Delete multi-mode validation scripts
  - Delete `pixel-sm-server/scripts/validate-mode-launch-matrix.sh`.
  - Delete `pixel-sm-server/scripts/qa-mode-smoke.sh` (deprecated wrapper).
- [Todo] P1.4 - Delete non-Elite matchsettings templates
  - Delete `pixel-sm-server/templates/matchsettings/siege.txt`.
  - Delete `pixel-sm-server/templates/matchsettings/battle.txt`.
  - Delete `pixel-sm-server/templates/matchsettings/joust.txt`.
  - Delete `pixel-sm-server/templates/matchsettings/custom.txt`.
  - Keep `pixel-sm-server/templates/matchsettings/elite.txt`.
- [Todo] P1.5 - Delete non-Elite map directories
  - Delete `pixel-sm-server/maps/battle/` (2 `.Map.Gbx` files).
  - Delete `pixel-sm-server/maps/siege/` (3 `.Map.Gbx` files).
  - Delete `pixel-sm-server/maps/joust/` (1 `.Map.Gbx` file).
  - Keep `pixel-sm-server/maps/elite/` (9 `.Map.Gbx` files).

### Phase 2 - Simplify environment templates

- [Todo] P2.1 - Simplify `.env.example`
  - Hardcode `PIXEL_SM_TITLE_PACK=SMStormElite@nadeolabs` (already is, but remove the comment about presets).
  - Change the comment on line 48-49 from `# Presets: elite | siege | battle | joust | custom` to `# Elite-only stack.`
  - Hardcode `PIXEL_SM_MODE=elite` (already is, but remove the multi-preset comment).
  - Remove or simplify the `PIXEL_SM_MATCHSETTINGS` comment (line 50-51) -- keep the variable but update comment to note that `elite.txt` is the default and override is optional.
- [Todo] P2.2 - Simplify `.env.production.example`
  - Same changes as `.env.example`: remove the preset listing comment on lines 45-48.
  - Hardcode `PIXEL_SM_MODE=elite`, remove comment about presets.

### Phase 3 - Simplify bootstrap.sh

This is the most impactful file. The goal is to remove all non-Elite code paths while keeping the script functional for Elite.

- [Todo] P3.1 - Simplify `resolve_mode_preset_name()`
  - Currently handles `elite|siege|battle|joust|custom` and defaults to `custom`.
  - Change to: always return `elite`. Any unrecognized mode falls through to `elite` with a warning.
- [Todo] P3.2 - Simplify `resolve_expected_title_pack_for_preset()`
  - Remove `siege|joust` and `battle` cases.
  - Only return `SMStormElite@nadeolabs` for `elite`. Default case returns empty string (for potential future override).
- [Todo] P3.3 - Simplify `resolve_expected_script_for_preset()`
  - Remove `siege`, `battle`, `joust` cases.
  - Only return `ShootMania\Elite\ElitePro.Script.txt` for `elite`.
- [Todo] P3.4 - Simplify `auto_injection_supported_for_matchsettings()`
  - Remove the non-Elite mode check (`siege|battle|joust|royal` returning 1).
  - Remove the script-name-based non-Elite check (`*siege*|*battle*|*joust*|*royal*` returning 1).
  - Simplify to always return 0 (Elite always supports auto-injection). Keep the function signature for forward compatibility.
- [Todo] P3.5 - Simplify `validate_matchsettings_mode_script()`
  - Remove the three title-pack pattern-matching blocks for battle, siege, and joust (lines 249-262).
  - Keep the generic file-based validation path (checking `GameData/Scripts/Modes/` and `UserData/Scripts/Modes/`).
- [Todo] P3.6 - Remove `PIXEL_SM_MODE` dependency from bootstrap
  - The `resolve_matchsettings_file()` function uses `PIXEL_SM_MODE` to pick the preset name. Hardcode the fallback to `elite` instead of `${PIXEL_SM_MODE:-custom}`.
  - In `render_runtime_files()`, the `resolve_mode_preset_name()` call uses `${PIXEL_SM_MODE:-custom}`. Change default to `elite`.
  - In `validate_mode_preset_expectations()`, same change.
  - In `ensure_matchsettings_has_playable_maps()`, the `auto_injection_supported_for_matchsettings` call reads `PIXEL_SM_MODE` -- simplify as per P3.4.
  - Keep `PIXEL_SM_MODE` as an env var that bootstrap can read, but always treat it as `elite` when it matters for preset resolution.

### Phase 4 - Simplify Docker and Compose config

- [Todo] P4.1 - Clean up `docker-compose.yml`
  - Remove the `PIXEL_SM_MODE: ${PIXEL_SM_MODE}` environment variable from the `shootmania` service (line 57). Bootstrap no longer needs it.
  - Keep `PIXEL_SM_MATCHSETTINGS` for override flexibility.
  - Keep `PIXEL_SM_TITLE_PACK` (still needed by bootstrap and the dedicated server command line).
- [Todo] P4.2 - Verify `docker-compose.production.yml` and `docker-compose.host.yml`
  - These files have no mode-specific logic. No changes needed (confirm only).
- [Todo] P4.3 - Verify `Dockerfile`
  - Dockerfile has no mode-specific logic. No changes needed (confirm only).

### Phase 5 - Simplify test-automated-suite.sh

- [Todo] P5.1 - Change default modes to Elite-only
  - Change `DEFAULT_MODES_CSV="elite,joust"` to `DEFAULT_MODES_CSV="elite"`.
- [Todo] P5.2 - Remove `dev-mode-compose.sh` dependency
  - The `run_mode_profile_apply()` function (line 394) calls `dev-mode-compose.sh`. Since there is only one mode and `dev-mode-compose.sh` is being deleted:
  - Replace `run_mode_profile_apply()` with a no-op or remove calls to it. The `.env` should already be configured for Elite, so no profile switching is needed.
  - Remove `DEV_MODE_SCRIPT` variable reference.
- [Todo] P5.3 - Remove mode matrix validation option
  - Remove `MODE_MATRIX_VALIDATION_SCRIPT` reference.
  - Remove `--with-mode-matrix-validation` flag and the `run_optional_mode_matrix_validation` function/call.
  - Update usage text to remove mode matrix references.
- [Todo] P5.4 - Update usage text and help
  - Update `--modes` default description to note Elite-only.
  - Remove `--with-mode-matrix-validation` from help text.

### Phase 6 - Update documentation

- [Todo] P6.1 - Update `pixel-sm-server/README.md`
  - Remove "Mode profile launch/relaunch" section (lines 199-213) referencing `dev-mode-compose.sh`.
  - Remove the "Mode presets and title packs" section (lines 402-434) -- replace with a brief note that this is an Elite-only stack using `SMStormElite@nadeolabs`.
  - Remove step 3 about provisioning Battle titlepack (lines 147-152).
  - Remove `validate-mode-launch-matrix.sh` from repository layout list (line 108) and validation flows section (lines 222-227).
  - Remove `dev-mode-compose.sh` from repository layout list (line 106).
  - Update "What you get" section (line 95) to remove "multi-mode checks (Elite, Siege, Battle, Joust, Custom)".
  - Update "Troubleshooting" section to remove "Battle mode fails" entry.
  - Update automated suite description to remove joust reference (line 258).
  - Update the SERVER-VALIDATION-CHECKLIST references.
- [Todo] P6.2 - Update `pixel-sm-server/SERVER-VALIDATION-CHECKLIST.md`
  - Remove `bash scripts/validate-mode-launch-matrix.sh` from section 2 (line 27).
  - Remove `--modes elite,joust` from the automated suite command (line 32).
  - Section 4 "T4 - Team lock sur un 2eme mode team" -- remove this test case since only Elite is available.
- [Todo] P6.3 - Update `pixel-sm-server/maps/README.md`
  - Remove all Siege and Battle map entries and runtime compatibility notes.
  - Remove "Source maps" heading for Siege/Battle sections.
  - Keep only Elite map entries.
  - Remove Battle mode runtime note about `SMStormBattle@nadeolabs.Title.Pack.gbx`.
- [Todo] P6.4 - Update root `CLAUDE.md`
  - In the "Common commands" section for `pixel-sm-server`, remove the `bash scripts/dev-mode-compose.sh elite` and `bash scripts/dev-mode-compose.sh joust relaunch` examples.
  - Add a note that `pixel-sm-server/` is an Elite-only stack.
  - In the "Gotchas" section, remove the `SMStormBattle@nadeolabs` gotcha about Battle mode.
- [Todo] P6.5 - Update memory files
  - Update MEMORY.md to remove multi-mode references for `pixel-sm-server/`.

### Phase 7 - QA / verification

- [Todo] P7.1 - Run plugin PHP tests
  - Command: `bash pixel-control-plugin/scripts/check-quality.sh`
  - Expected: All PHP syntax checks pass.
- [Todo] P7.2 - Run server unit tests
  - Command: `cd pixel-control-server && npm run test`
  - Expected: All 272 tests pass (Vitest).
- [Todo] P7.3 - Run regression smoke tests
  - Run ALL existing smoke scripts to verify zero regressions:
    - `bash pixel-control-server/scripts/qa-p0-smoke.sh` (43 assertions)
    - `bash pixel-control-server/scripts/qa-p1-smoke.sh` (35 assertions)
    - `bash pixel-control-server/scripts/qa-p2-smoke.sh` (94 assertions)
    - `bash pixel-control-server/scripts/qa-p2.5-smoke.sh` (59 assertions)
    - `bash pixel-control-server/scripts/qa-p2.6-smoke.sh` (29 assertions)
    - `bash pixel-control-server/scripts/qa-p2.6-elite-smoke.sh` (21 assertions)
    - `bash pixel-control-server/scripts/qa-elite-enrichment-smoke.sh` (53 assertions)
    - `bash pixel-control-server/scripts/qa-full-integration.sh` (255 assertions)
  - Expected: All pass with zero failures.

## Success criteria

- All non-Elite files (mode profiles, matchsettings, maps, scripts) are removed.
- `bootstrap.sh` only handles Elite mode; no siege/battle/joust/royal code paths remain.
- `.env.example` and `.env.production.example` are Elite-hardcoded with no multi-mode instructions.
- `test-automated-suite.sh` defaults to Elite-only and does not depend on deleted scripts.
- `README.md` and other docs reflect Elite-only without referencing deleted files or non-Elite modes.
- Stack remains fully functional for Elite mode (no regressions).
- All 272 server unit tests pass.
- All 8 smoke test scripts pass.
- Plugin PHP syntax checks pass.

## Notes / outcomes

Executed 2026-03-01 on branch `feat/p2-read-api`.

### Files deleted
- `pixel-sm-server/.env.elite`, `.env.joust`
- `pixel-sm-server/scripts/dev-mode-compose.sh`
- `pixel-sm-server/scripts/validate-mode-launch-matrix.sh`
- `pixel-sm-server/scripts/qa-mode-smoke.sh`
- `pixel-sm-server/templates/matchsettings/siege.txt`, `battle.txt`, `joust.txt`, `custom.txt`
- `pixel-sm-server/maps/battle/` (2 files), `maps/siege/` (3 files), `maps/joust/` (1 file)

### Key simplifications
- `bootstrap.sh`: `resolve_mode_preset_name()` now always returns `elite` (with warning for unrecognized modes). `auto_injection_supported_for_matchsettings()` is a single-line `return 0`. All non-Elite case branches in preset/script/title-pack resolvers removed. Pattern-matching blocks for battle/siege/joust in `validate_matchsettings_mode_script()` removed.
- `docker-compose.yml`: `PIXEL_SM_MODE` env var removed from shootmania service.
- `test-automated-suite.sh`: `DEFAULT_MODES_CSV="elite"`. `DEV_MODE_SCRIPT`, `MODE_MATRIX_VALIDATION_SCRIPT`, `WITH_MODE_MATRIX_VALIDATION` removed. `run_mode_profile_apply()`, `run_optional_mode_matrix_validation()`, `--with-mode-matrix-validation`, `--with-mode-smoke` removed. Recovery logic now uses `docker compose up -d --wait`. Profile-apply check steps removed from `run_elite_strict_gate()` and `run_mode_veto_response_assertions()`.
- `.env.example`, `.env.production.example`: preset listing comment replaced with "Elite-only stack." comment.
- `maps/README.md`: rewritten as Elite-only (Siege/Battle sections removed).
- `README.md`: Multi-mode sections removed. Mode presets table replaced with Elite-only note.
- `SERVER-VALIDATION-CHECKLIST.md`: `validate-mode-launch-matrix.sh` and T4 test case removed.
- Root `CLAUDE.md`: dev-mode-compose examples and Battle gotcha removed.

### QA results
- PHP plugin tests: 29/29 passed
- NestJS unit tests: 272/272 passed
- qa-p0-smoke.sh: 43/43 passed
- qa-p1-smoke.sh: 35/35 passed
- qa-p2-smoke.sh: 94/94 passed
- qa-p2.5-smoke.sh: 59/59 passed
- qa-p2.6-smoke.sh: 29/29 passed
- qa-p2.6-elite-smoke.sh: 21/21 passed
- qa-elite-enrichment-smoke.sh: 53/53 passed
- qa-full-integration.sh: 255/255 passed
