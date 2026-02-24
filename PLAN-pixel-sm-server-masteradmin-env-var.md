# PLAN - Wire MasterAdmin login env var in `pixel-sm-server` (2026-02-24)

## Context

- Purpose: allow operators to define the ManiaControl `MasterAdmin` login at startup through one environment variable.
- Scope: `pixel-sm-server/` only (template rendering path, compose/env wiring, and minimal docs/env templates).
- Background / findings:
  - `pixel-sm-server/templates/maniacontrol/server.template.xml` currently has an empty `<masteradmins>` section.
  - `pixel-sm-server/scripts/bootstrap.sh` renders `server.xml` with `envsubst`, but currently substitutes only DB/XML-RPC/SuperAdmin-related vars.
  - `pixel-sm-server/docker-compose.yml` does not currently pass a MasterAdmin login variable.
  - `pixel-sm-server/.env.example`, `pixel-sm-server/.env`, `pixel-sm-server/.env.elite`, and `pixel-sm-server/.env.joust` do not currently define a MasterAdmin login variable.
- Constraints / assumptions:
  - Introduce exactly one new environment variable dedicated to MasterAdmin login.
  - Keep behavior backward-compatible when the variable is unset/empty (no forced extra behavior outside explicit configuration).
  - Keep changes limited to `pixel-sm-server/`.

## Steps

- [Done] S1 - Define the canonical variable contract.
  - Pick one variable name (for example `PIXEL_SM_MASTERADMIN_LOGIN`) and document expected value format (single ManiaPlanet login).
  - Define empty-value behavior for generated `server.xml`.
  - Decision: use `PIXEL_SM_MANIACONTROL_MASTERADMIN_LOGIN` as a single ManiaPlanet login string; when empty/unset, rendered XML keeps `<masteradmins><login></login></masteradmins>` so structure stays valid.
- [Done] S2 - Wire the variable through launch-time config rendering.
  - Add the variable to `pixel-sm-server/docker-compose.yml` service environment.
  - Extend `pixel-sm-server/scripts/bootstrap.sh` `envsubst` variable list so `server.xml` receives the value.
- [Done] S3 - Inject MasterAdmin into ManiaControl template output.
  - Update `pixel-sm-server/templates/maniacontrol/server.template.xml` so `<masteradmins>` is populated from the new variable at render time.
  - Preserve valid XML structure for both configured and empty cases.
- [Done] S4 - Update env templates/docs minimally.
  - Add the new variable to `pixel-sm-server/.env.example`, `pixel-sm-server/.env`, `pixel-sm-server/.env.elite`, and `pixel-sm-server/.env.joust`.
  - Update only the minimal documentation line(s) needed to explain the variable usage.
- [Done] S5 - Run static consistency validation.
  - Verify the variable is referenced consistently across compose, bootstrap, template, and env templates.
  - Run `bash -n pixel-sm-server/scripts/bootstrap.sh`.
  - Run `docker compose -f pixel-sm-server/docker-compose.yml config` to confirm compose interpolation is valid.
  - Perform a local render check (template -> generated `server.xml`) and confirm `<masteradmins>` reflects configured value.
  - Validation results: cross-file `rg --hidden --no-ignore` reference scan passed; `bash -n` passed; `docker compose ... config` passed with interpolated `PIXEL_SM_MANIACONTROL_MASTERADMIN_LOGIN`; template render sanity passed for configured and empty values using `envsubst` + Python XML parse.

## Success criteria

- A single new env var controls MasterAdmin login at startup for `pixel-sm-server`.
- Generated ManiaControl `server.xml` contains the expected `<masteradmins>` content when configured.
- Compose/bootstrap/template/env files are consistent and static validation checks pass.
