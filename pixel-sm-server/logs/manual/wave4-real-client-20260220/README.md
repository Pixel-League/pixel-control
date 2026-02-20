# Wave 4 Manual Gameplay Evidence (2026-02-20)

This directory is the canonical storage path for real-client manual validation artifacts required after deterministic wave-4 closure.

## Required manual checks

- reconnect continuity behavior under real client reconnect (same login/session chain semantics)
- side/team change ordering under real admin or gameplay transitions
- team aggregate correctness against observed match context
- win-context semantics (`result_state`, `winning_side`, `winning_reason`) under real rounds/maps
- veto/draft actor/result behavior with real callback payloads when available

## Artifact naming convention

- screenshots: `YYYYMMDD-HHMMSS-<scenario>-<index>.png`
- raw exported payloads: `YYYYMMDD-HHMMSS-<scenario>-payload.ndjson`
- operator notes: `YYYYMMDD-HHMMSS-<scenario>-notes.md`

## Minimum evidence set per manual session

- one timeline note file with exact scenario steps and expected/observed outcomes
- one payload capture (or explicit statement why payload capture was unavailable)
- one screenshot/video reference for reconnect/side-change and veto-result checkpoints

Use `INDEX.md` in this directory to register each manual session and link to its files.
