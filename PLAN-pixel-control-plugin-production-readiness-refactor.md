# PLAN - Pixel Control plugin production-readiness refactor (2026-02-23)

## Context

- Purpose: deliver a full production-readiness refactor of `pixel-control-plugin/` with clearer architecture, stronger tests, and essential documentation only (README-centric), while keeping plugin development workflows practical.
- Scope:
  - In scope:
    - Refactor plugin code organization under `pixel-control-plugin/src/` (especially very large domain traits).
    - Introduce/strengthen plugin-local automated tests and quality gates.
    - Consolidate documentation into a long but essential `pixel-control-plugin/README.md`.
    - Keep only essential docs in `pixel-control-plugin/docs/` (admin commands, veto commands, test scripts, other scripts, plus required machine contract artifacts).
    - Rename ambiguous script/file names used for plugin development workflows (remove `qa` / `smoke` naming in favor of explicit names), including call-site updates.
  - Out of scope:
    - Backend implementation work in `pixel-control-server/`.
    - Any mutable changes under `ressources/`.
    - Product-feature expansion unrelated to refactor/readability/operability.
- Background / findings (deep recon):
  - Plugin codebase size is high for current architecture: `18,701` PHP LOC across `47` files.
  - Largest files indicate refactor hotspots:
    - `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php` (`3,094` LOC)
    - `pixel-control-plugin/src/Domain/Player/PlayerDomainTrait.php` (`1,758` LOC)
    - `pixel-control-plugin/src/Admin/NativeAdminGateway.php` (`1,571` LOC)
    - `pixel-control-plugin/src/Domain/Match/MatchDomainTrait.php` (`1,162` LOC)
    - `pixel-control-plugin/src/Domain/Admin/AdminControlDomainTrait.php` (`1,106` LOC)
  - Documentation is fragmented and partially redundant across `README.md`, `FEATURES.md`, and multiple long documents in `pixel-control-plugin/docs/`.
  - Plugin-local automated test suite is currently missing (no `tests/` in `pixel-control-plugin/`).
  - Plugin docs currently reference many scripts with non-explicit names (`qa-*`, `*smoke*`) from `pixel-sm-server/scripts/`.
- Goals:
  - Build a modular, maintainable plugin architecture (SRP-first decomposition of monolithic traits).
  - Establish repeatable automated validation for critical plugin behavior.
  - Make `pixel-control-plugin/README.md` the canonical and practical developer/operator guide.
  - Keep only essential, actionable docs and remove vague or duplicated narrative docs.
  - Make script/file naming explicit and understandable for non-context-heavy readers.
- Non-goals:
  - No change of product direction (plugin-first, multi-mode support remains mandatory).
  - No breaking contract changes unless explicitly justified and versioned.
  - No deletion of machine-consumed schema artifacts still required by contract/tooling.
- Constraints / assumptions:
  - Preserve runtime behavior and control-surface compatibility while refactoring.
  - Preserve additive compatibility of current schema baseline unless an explicit version bump is approved.
  - Keep `ressources/` read-only.
  - Respect existing modular script preference (one script per action/check descriptor where applicable).
  - For script renaming, provide migration-safe compatibility wrappers/aliases during transition.
  - After plugin source changes during execution, hot-sync must be run via `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh` before reporting validation results.
- Dependencies / stakeholders:
  - User requested phase-by-phase review before progressing.
  - Plugin code and script workflows are coupled with `pixel-sm-server/` automation.
- Risks / open questions:
  - High rename blast radius (scripts referenced by docs, plans, AGENTS memory, and helper orchestration).
  - Large-file decomposition can introduce subtle behavior regressions without strong regression tests.
  - Documentation consolidation can accidentally remove useful operational detail if retention criteria are not strict.

## Planned file touch map

Likely existing files to edit (grouped):

- Plugin runtime entrypoint and large domain traits:
  - `pixel-control-plugin/src/PixelControlPlugin.php`
  - `pixel-control-plugin/src/Domain/Core/CoreDomainTrait.php`
  - `pixel-control-plugin/src/Domain/Admin/AdminControlDomainTrait.php`
  - `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php`
  - `pixel-control-plugin/src/Domain/Player/PlayerDomainTrait.php`
  - `pixel-control-plugin/src/Domain/Match/MatchDomainTrait.php`
  - `pixel-control-plugin/src/Domain/AccessControl/AccessControlDomainTrait.php`
  - `pixel-control-plugin/src/Domain/TeamControl/TeamControlDomainTrait.php`
  - `pixel-control-plugin/src/Admin/NativeAdminGateway.php`
- Plugin docs/content:
  - `pixel-control-plugin/README.md`
  - `pixel-control-plugin/FEATURES.md`
  - `pixel-control-plugin/docs/*.md` (essentialization pass)
- Plugin-related script surface and descriptors:
  - `pixel-sm-server/scripts/*.sh` (currently `qa-*` / `*smoke*` naming)
  - `pixel-sm-server/scripts/qa-admin-matrix-actions/*`
  - `pixel-sm-server/scripts/qa-veto-matrix-actions/*`
  - `pixel-sm-server/scripts/automated-suite/README.md`
  - `pixel-sm-server/scripts/test-automated-suite.sh`

Likely new files/directories to add:

- Refactor extraction modules under `pixel-control-plugin/src/Domain/**` and/or focused service folders.
- Plugin test harness + tests:
  - `pixel-control-plugin/tests/**`
  - `pixel-control-plugin/phpunit.xml` (or equivalent)
  - `pixel-control-plugin/composer.json` (if needed for isolated test tooling)
- Compatibility wrappers for renamed scripts (temporary deprecation bridge).
- Optional migration map artifact for renamed scripts/files (for review and onboarding).

## Phase review protocol (mandatory)

- [Todo] Each phase ends with a review packet: changed-file map, rationale, risks, and verification evidence.
- [Done] User-overridden protocol (2026-02-23): executor continues across phases without pausing, and consolidates user review once all requested changes are delivered. (Superseded later on 2026-02-23.)
- [Done] User review protocol update (2026-02-23): executor must stop after each completed phase and wait for user review before starting the next phase. (Superseded by later user instruction to continue all remaining phases.)
- [Todo] No parallel phase execution; phases are strictly sequential for reviewability.
- [Done] Each completed phase must be reported to user with an essential-only summary and immediate next steps.
- [Done] Any user-requested extra change/revert must be appended as `[Todo]` in the active phase, then executed before leaving that phase.

## Steps

- [Done] Phase 0 - Baseline freeze and refactor contract
- [Done] Phase 1 - Target architecture and extraction blueprint
- [Done] Phase 2 - Core monolith decomposition (high-risk domains)
- [Done] Phase 3 - Remaining domain cleanup and consistency pass
- [Done] Phase 4 - Testing foundation and regression coverage
- [Done] Phase 5 - Documentation consolidation and essential-only docs
- [In progress] Phase 6 - Script/file naming clarity migration
- [Todo] Phase 7 - Production-readiness validation and handoff

### Phase 0 - Baseline freeze and refactor contract

- [Done] P0.1 - Capture baseline inventory and complexity map.
  - Produce authoritative list of plugin files, current responsibilities, and hotspots by size/churn/risk.
- [Done] P0.2 - Build documentation retention matrix (`keep`, `merge into README`, `delete`).
  - Explicitly classify each current file in `pixel-control-plugin/docs/` and `FEATURES.md`.
- [Done] P0.3 - Build naming migration map for scripts/files.
  - Define `old_name -> new_explicit_name` mapping for all plugin-facing scripts currently using `qa`/`smoke` naming.
- [Done] P0.4 - Freeze acceptance gates for each next phase.
  - Define measurable pass conditions per phase before coding starts.
- [Done] P0.5 - Register execution-reporting and change-request protocol.
  - Record that each delivered phase requires an essential-only execution summary with immediate next steps.
  - Record that any user-requested extra change/revert must be appended as `[Todo]` in the active phase before execution.
- [Done] P0.6 - Apply user-requested review-flow adjustment.
  - User requested to postpone review until all requested changes are complete.
  - Per-phase review checkpoints stay documented but are no longer blocking for execution sequencing.
- [Done] P0.R - Review checkpoint with user.
  - Validate scope, deletion policy, and naming strategy before implementation.

### Phase 1 - Target architecture and extraction blueprint

- [Done] P1.1 - Define domain boundaries and dependency rules.
  - Separate orchestration, state, command parsing, transport integration, and telemetry assembly responsibilities.
- [Done] P1.2 - Create extraction plan for large traits.
  - Prioritize `VetoDraft`, `Admin`, `Player`, and `Match` traits into smaller focused modules.
- [Done] P1.3 - Define wiring strategy in `PixelControlPlugin`.
  - Keep entrypoint readable and lifecycle-safe with explicit construction/wiring points.
- [Done] P1.4 - Define backward-compatibility strategy.
  - Preserve command names, communication methods, payload keys, and fallback behaviors unless explicitly versioned.
- [Done] P1.R - Review checkpoint with user.
  - Validate architecture blueprint before extraction starts.
  - Execution note (2026-02-23): checkpoint completed as internal review packet; user review deferred to consolidated final review per `P0.6`.

### Phase 2 - Core monolith decomposition (high-risk domains)

- [Done] P2.1 - Decompose veto/draft runtime orchestration.
  - Split command handling, communication handlers, lifecycle automation, and chat/status rendering paths into cohesive modules.
- [Done] P2.2 - Decompose admin control execution flow.
  - Split request parsing, action normalization, permission/security handling, persistence/rollback, and correlation concerns.
- [Done] P2.3 - Decompose match/player heavy logic.
  - Move correlation/state-delta/reconnect/team-side logic into focused helpers/services.
- [Done] P2.4 - Keep behavior parity during extraction.
  - Add parity checks or fixtures for pre/post behavior in high-risk paths.
- [Done] P2.R - Review checkpoint with user.
  - Present decomposition diff and parity status before continuing.
  - Execution note (2026-02-23): checkpoint completed as internal review packet; user review deferred to consolidated final review per `P0.6`.

### Phase 3 - Remaining domain cleanup and consistency pass

- [Done] P3.0 - Remove unused imports/variables/functions in refactored PHP domain files.
  - Scope: phase-2 split traits and their facades (`Domain/VetoDraft`, `Domain/Admin`, `Domain/Player`, `Domain/Match`).
  - Keep behavior unchanged; only delete dead/unused symbols.
- [Done] P3.1 - Refactor access/team/series supporting domains for consistency.
  - Align coding style, error semantics, and state snapshot behavior.
- [Done] P3.2 - Centralize runtime setting resolution patterns.
  - Remove repeated env/setting parsing logic where possible through shared helpers.
- [Done] P3.3 - Remove dead code and stale abstractions.
  - Delete unreachable helpers and duplicate pathways left by decomposition.
- [Done] P3.4 - Enforce naming/readability consistency in source files.
  - Ensure filenames and module names clearly express responsibility.
- [Done] P3.R - Review checkpoint with user.
  - Validate structural cleanup and naming decisions.
  - Execution note (2026-02-23): checkpoint completed as internal review packet; user review deferred to consolidated final review per `P0.6`.

### Phase 4 - Testing foundation and regression coverage

- [Done] P4.1 - Introduce plugin-local test harness.
  - Add test runner/config and minimal bootstrap for isolated plugin tests.
- [Done] P4.2 - Add unit tests for deterministic state modules.
  - Cover whitelist, vote policy, team roster, series control, and veto session state logic.
- [Done] P4.3 - Add behavior tests for command/control normalization.
  - Validate admin action parameter normalization, veto command parsing, and key fallback/error paths.
- [Done] P4.4 - Add integration-style regression checks for refactored orchestration seams.
  - Focus on high-risk branches (ready gate, lifecycle completion, persistence rollback, permission model).
- [Done] P4.5 - Define and run quality gate commands.
  - Include PHP lint + automated tests in a repeatable sequence.
- [Done] P4.R - Review checkpoint with user.
  - Present test matrix, pass/fail summary, and remaining coverage gaps.

### Phase 5 - Documentation consolidation and essential-only docs

- [Done] P5.0 - Register user-requested review-gate restoration in active phase.
  - Stop after each completed phase and wait for user review before entering the next phase.
- [Done] P5.0.b - Register user-requested execution cadence override.
  - Continue through all remaining phases without pausing.
  - Create one local commit per completed phase, and do not push.
- [Done] P5.1 - Rewrite `pixel-control-plugin/README.md` as canonical operational document.
  - Keep it long if needed, but only practical/essential content.
- [Done] P5.2 - Ensure README contains required essential sections.
  - Admin command documentation.
  - Veto command documentation.
  - Test scripts documentation.
  - Other development scripts documentation.
- [Done] P5.3 - Remove or merge vague/redundant docs.
  - Decommission duplicated narrative docs and fold valid content into README or concise retained docs.
- [Done] P5.4 - Preserve required machine-contract artifacts and explicitly document why they remain.
  - Keep schema/catalog files only when they are operationally required.
- [Done] P5.R - Review checkpoint with user.
  - Validate final doc set and README structure before naming migration lands.

### Phase 6 - Script/file naming clarity migration

- [Todo] P6.1 - Rename plugin-related scripts to explicit names.
  - Remove `qa`/`smoke` terminology from active script names in plugin-facing workflows.
- [Todo] P6.2 - Rename matrix/descriptors directories where needed while preserving modular structure.
  - Keep one-file-per-action/feature extension pattern intact.
- [Todo] P6.3 - Update all call sites and documentation references.
  - README, script internals, helper docs, and automation runners.
- [Todo] P6.4 - Add temporary backward-compatible wrappers/aliases.
  - Old script names should forward to new names with deprecation notice to reduce migration risk.
- [Todo] P6.R - Review checkpoint with user.
  - Validate naming choices and migration compatibility before final hardening.

### Phase 7 - Production-readiness validation and handoff

- [Todo] P7.1 - Run full validation suite on refactored code.
  - PHP lint, plugin-local tests, and renamed script validation flows.
- [Todo] P7.2 - Execute plugin workflow verification with renamed scripts.
  - Validate key admin/veto/dev flows and generated artifacts are still coherent.
- [Todo] P7.3 - Confirm compatibility boundaries.
  - Verify command surface, communication methods, and schema expectations remain stable.
- [Todo] P7.4 - Prepare migration/handoff summary.
  - Include architecture map, rename map, removed docs list, and known follow-ups.
- [Todo] P7.R - Final review checkpoint with user.
  - Approve production-readiness package and finalize refactor scope.

## Evidence / Artifacts

- Planned baseline/refactor evidence root: `pixel-control-plugin/logs/refactor-production-readiness/`.
- Planned migration map artifact: `pixel-control-plugin/logs/refactor-production-readiness/script-name-migration-map.md`.
- Planned phase review packets: `pixel-control-plugin/logs/refactor-production-readiness/phase-<n>-review.md`.

## Success criteria

- Architecture:
  - Critical monolithic domains are decomposed into smaller, responsibility-focused modules.
  - `PixelControlPlugin` wiring is clear and maintainable.
- Tests:
  - Plugin-local automated tests exist and cover critical deterministic logic and regression-prone flows.
  - Refactor completion is gated by reproducible lint/test commands.
- Documentation:
  - `pixel-control-plugin/README.md` contains all essential operational docs requested.
  - `pixel-control-plugin/docs/` no longer contains vague/redundant narrative documents.
- Naming clarity:
  - Plugin-facing scripts/files use explicit human-readable names (no active `qa`/`smoke` naming in the targeted workflows).
  - Migration compatibility is documented and executable during transition.
- Stability:
  - No unintended regression in admin/veto behavior and plugin communication surfaces.

## Notes / outcomes

- This plan is intentionally segmented with mandatory review gates to match the requested step-by-step review workflow.
- Execution must be done by the Executor agent after this planning handoff.
