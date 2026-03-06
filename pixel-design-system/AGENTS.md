# Local AGENTS.md

## Project Context
- **Package**: `lib-neumorphic` (standalone React + TypeScript design system package).
- **Origin**: Built from Pixel Series **Gen 5 / Iteration 5 (Combined Polish)**.
- **Goal**: Reusable neumorphic component library with Storybook demos and test coverage.

## Tech Stack
- React + TypeScript + Vite.
- Tailwind CSS (config-driven tokens).
- Storybook for documentation and interaction demos.
- Vitest + Testing Library for unit/integration behavior.

## Pixel Series Design DNA (Must Keep)
- **0px border radius everywhere** (exception: `rounded-full` only when required for radio control functionality).
- **Neumorphic surfaces**: dual outer shadows (light + dark) on uniform backgrounds; component surface matches parent surface.
- **Brand colors**:
  - `px-primary: #2C12D9`
  - `px-primary-light: #4A35E0`
  - `px-primary-dark: #1E0C96`
  - `px-error: #E02020`
  - `px-success: #00C853`
  - `px-warning: #FFB020`
- **Typography direction**: display text is uppercase with `tracking-display: 3px`.
- **Active/pressed interaction rule**: no inset pressed shadows on active states; use reduced outer shadow only and no active scale transform.

## Gen 5 Combined Polish Baseline (Visual Reference)
- Refined shadows: blur increased to 10px, softer depth profile.
- Enhanced borders: edge opacity raised to 8% (`border-white/[0.08]`, `border-black/[0.08]`).
- Deeper inputs: inset depth increased (5px/10px) and focus ring strengthened to 3px.
- Warmer labels/surfaces:
  - label tone `#7B7FA0`
  - dark surface `#1C1B1F`
  - light surface `#E6E4EB`

## Component Coverage
- **26 components**, each with:
  - implementation (`.tsx`)
  - Storybook story (`.stories.tsx`)
  - unit test (`.test.tsx`)
- Categories:
  - Form primitives: Button, Input, Textarea, Select, Checkbox, Radio, Switch, FormField, FileInput
  - Feedback: Alert, Toast, Badge, Progress (bar + circle), Skeleton
  - Data display: Card, Table, Avatar, Divider, Bracket
  - Navigation: Tabs, Breadcrumb, Pagination, TopNav
  - Overlay/interaction: Modal, Tooltip, DropdownMenu

## Storybook Conventions
- Interactive components provide `PresentationPage` demos with trigger-driven states:
  - `Toast`: placement matrix (`top-left`, `top-center`, `top-right`, `bottom-left`, `bottom-center`, `bottom-right`)
  - `Modal`: size presets (`sm`, `md`, `lg`) + overlay close toggle
  - `Tooltip`: positions (`top`, `right`, `bottom`, `left`) + delay controls
  - `DropdownMenu`: trigger + alignment (`left`, `right`) + menu preset switching
- Foundations:
  - Color palette is generated from `tailwind.config.ts` through `src/foundations/tailwindPalette.ts`.
  - Typography foundation is exposed under `Foundations/Text` and aligned to Figma frame `535:1886`.

## Tokens and Architecture Notes
- Typography variables exported from `src/tokens/tokens.ts` as `typographyVariables`.
- Layout-related maps exported from `src/tokens/tokens.ts` as:
  - `layoutVariables`
  - `spacingVariables`
  - `radiusVariables`
  - `shadowVariables`
- `Table` generic uses `T extends object` (not `Record<string, unknown>`) to preserve typed row interfaces.

## Bracket Conventions
- Data model is deterministic and ID-driven (`nextMatchId` + `nextSlot`) for winner/loser flow, with grand final and optional ranking badges.
- Connectors render via SVG overlay and are recomputed on mount, `ResizeObserver`, and window resize.
- Connector tests should assert linkage attributes (`data-source-match-id`, `data-target-match-id`) rather than pixel geometry.
- Fixtures live in `src/components/Bracket/Bracket.fixtures.ts` and include completed + in-progress states.
- Team names can repeat across rounds/finals; prefer `getAllByText` (or scoped queries) over single-match text queries.

## Testing Conventions
- Tooltip tests should use `fireEvent.mouseEnter` / `fireEvent.mouseLeave` from Testing Library.
- Toast dismiss behavior is animated; tests should `waitFor` callback completion (not immediate sync assertion).
- Tooltip hide assertions must advance fake timers past exit transition duration.
- Modal unmount is delayed to preserve exit animations; test with async expectations.

## Build Gotcha
- `src/foundations/tailwindPalette.ts` imports `../../tailwind.config.ts`.
- This can trigger `TS6059` during declaration builds if `rootDir` is `src`.
- Keep `src/foundations/**/*` excluded in `tsconfig.build.json` (Storybook-only path).

## Validation Commands
- Run from `lib-neumorphic/`:
  - `npm run typecheck`
  - `npm run test`
  - `npm run build-storybook`
  - `npm run build`
