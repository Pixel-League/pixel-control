# PLAN - Compact `//pcadmin` help output by command family (2026-02-24)

## Context

- Purpose: prevent `//pcadmin` help output from overflowing ManiaControl chat scroll by reducing action help lines to one line per command family.
- Scope: `pixel-control-plugin/` admin chat help formatting only (no behavior change for command parsing, permissions, execution, or communication payload contracts).
- Background / findings:
  - Help rendering is currently in `pixel-control-plugin/src/Domain/Admin/AdminControlIngressTrait.php` via `sendAdminControlHelp(...)`.
  - Current help output emits one line per action using `formatAdminActionHelpLine(...)`, which is too verbose for chat scroll.
  - Action names come from `pixel-control-plugin/src/Admin/AdminActionCatalog.php` and are dot-separated (for example `map.skip`, `team.roster.assign`, `match.bo.set`).
  - Plugin-local test seam exists via `AdminLinkAuthHarness` + `FakeManiaControl`/`FakeChat` in `pixel-control-plugin/tests/Support/` and can validate emitted chat lines deterministically.
- Goals:
  - Keep usage and server-link info lines unchanged.
  - Render action help as one chat line per command family.
  - Keep output deterministic, readable, and compact for ManiaControl chat constraints.
  - Keep all non-help behavior backward-compatible.
- Non-goals:
  - No changes to delegated action catalog semantics or permissions.
  - No communication API/schema changes (`PixelControl.Admin.ListActions` / `ExecuteAction`).
  - No backend (`pixel-control-server/`) or `ressources/` changes.
- Constraints / assumptions:
  - Family grouping policy should be deterministic and derived from catalog names, not hardcoded per action.
  - Recommended family key policy: action name prefix before final segment (examples: `map.skip` -> `map`, `team.roster.assign` -> `team.roster`, `match.bo.set` -> `match.bo`).
  - Formatting should favor concise separators and avoid repeating full action prefixes per item.

## Expected file changes

- Required:
  - `pixel-control-plugin/src/Domain/Admin/AdminControlIngressTrait.php`
  - `pixel-control-plugin/tests/cases/21AdminLinkAuthTest.php`
- Optional (only if needed for test helper extraction/readability):
  - `pixel-control-plugin/tests/Support/Harnesses.php`

## Steps

- [Done] Phase 0 - Freeze compact help format contract
- [Done] Phase 1 - Implement family-grouped admin help renderer
- [Done] Phase 2 - Add plugin-local deterministic regression coverage
- [Done] Phase 3 - Validate syntax/tests and capture handoff checks

### Phase 0 - Freeze compact help format contract

- [Done] P0.1 - Define command-family derivation and ordering.
  - Family key: prefix before last dot segment.
  - Action label inside family: last segment (verb token).
  - Sort families alphabetically; sort actions within family alphabetically for deterministic output.
- [Done] P0.2 - Define line format for ManiaControl readability.
  - Keep the first 3 lines unchanged:
    - `Pixel delegated admin actions (<count>).`
    - `Usage: //<command> <action> key=value ...`
    - `Server link commands ...`
  - Family line target format: `- <family>: <action>(params?) | <action>(params?) ...`.
  - Parameter hint policy: include compact required/optional hints only when non-empty; keep separators concise.
- [Done] P0.3 - Explicit compatibility guard.
  - Only chat help formatting changes; execution flow, permission checks, and response codes remain untouched.

### Phase 1 - Implement family-grouped admin help renderer

- [Done] P1.1 - Replace per-action chat loop in `sendAdminControlHelp(...)` with grouped family emission.
  - Build a normalized family map from `AdminActionCatalog::getActionDefinitions()`.
  - Keep action-count header based on full action count (not family count) for continuity.
- [Done] P1.2 - Add focused private helpers for grouping/formatting.
  - Extract family key from action name.
  - Extract action suffix label.
  - Build compact parameter hint per action.
  - Format final family chat line.
- [Done] P1.3 - Remove or repurpose obsolete per-action-only formatting helpers if no longer used.
  - Keep trait structure tidy without changing external behavior.

### Phase 2 - Add plugin-local deterministic regression coverage

- [Done] P2.1 - Add a new test case for `//pcadmin help` output compaction in `21AdminLinkAuthTest.php`.
  - Execute `runCommand(... '//pcadmin help' ...)` through `AdminLinkAuthHarness`.
  - Assert usage and server-link lines are still present.
  - Assert help body is grouped by family (family line count equals derived unique family count).
  - Assert old verbose per-action line shape is no longer emitted (for example no standalone `- map.skip`-style line).
- [Done] P2.2 - Add readability-focused deterministic assertions.
  - Assert grouped lines use compact separators and action suffixes (no repeated full-family prefix for every item).
  - Assert representative deep families render on one line (for example `team.roster`, `match.bo`).

### Phase 3 - Validate syntax/tests and capture handoff checks

- [Done] P3.1 - Run required PHP lint on touched files.
  - `php -l pixel-control-plugin/src/Domain/Admin/AdminControlIngressTrait.php`
  - `php -l pixel-control-plugin/tests/cases/21AdminLinkAuthTest.php`
  - Plus `php -l` for any additional touched plugin PHP file.
- [Done] P3.2 - Run targeted plugin-local tests for the changed seam.
  - `php pixel-control-plugin/tests/run.php --filter=21AdminLinkAuthTest.php`
  - If needed for narrower confirmation: `php pixel-control-plugin/tests/run.php --filter=pcadmin help`.
- [Done] P3.3 - Optional broader gate if touched scope expands.
  - `bash pixel-control-plugin/scripts/check-quality.sh`.

## Validation strategy

- Required:
  - PHP lint for every touched plugin PHP file.
  - Targeted plugin-local test run covering `//pcadmin help` compaction behavior.
- Fallback (only if test seam proves insufficient):
  - Deterministic manual chat verification in local runtime with `//pcadmin help`, confirming:
    - header/usage/server-link lines remain,
    - one line per derived family,
    - no per-action flood.

## Success criteria

- `//pcadmin help` output now emits one line per command family instead of one line per action.
- Usage + server-link informational lines remain present and readable.
- No functional regression outside help formatting (action execution and permissions unchanged).
- Required lint and targeted tests pass.
