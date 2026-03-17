---
phase: 01-cli-state-machine
plan: "01"
subsystem: ui
tags: [cli, state-machine, pbq, cisco-ios, vue, javascript]

# Dependency graph
requires: []
provides:
  - "DOMAIN_SCHEMAS: prompt schemas for cisco_ios, linux, windows, sql, generic"
  - "evaluateCommand(): 4-step pipeline (static transition -> dynamic transition -> command_outputs -> error fallback)"
  - "getPrompt(): prompt string generator with domain/mode/host/context support"
affects:
  - 01-02  # PbqCli.vue integration (Wave 2)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Pure ES module with zero external dependencies for domain logic isolation"
    - "4-step evaluation pipeline: static transitions first, dynamic transitions second, command_outputs third, error last"
    - "Dynamic regex transitions for Cisco IOS interface commands (e.g. 'interface Fa0/0' -> config-if mode)"
    - "Domain fallback: unknown domain key resolves to generic schema"

key-files:
  created:
    - app/src/utils/cliStateMachine.js
  modified: []

key-decisions:
  - "Logic isolated in pure JS utility module (no Vue dependency) so it can be reused in Author Tool live preview"
  - "Dynamic transitions match against original trimmed cmd (not normalized) to preserve interface name casing"
  - "errorMsg can be string or function — function receives cmd.trim() for domain-specific messages (bash, windows)"

patterns-established:
  - "Domain schema pattern: { modes, defaultMode, transitions, dynamicTransitions, errorMsg }"
  - "evaluateCommand returns { type, nextMode, nextContext, lines } always — caller updates state unconditionally"
  - "Context is spread on every return path so mutations never bleed between evaluations"

requirements-completed: [CLI-01, CLI-04]

# Metrics
duration: 3min
completed: 2026-03-16
---

# Phase 1 Plan 01: CLI State Machine Summary

**Pure ES module exporting DOMAIN_SCHEMAS (5 domains), evaluateCommand() 4-step pipeline, and getPrompt() for Cisco IOS, Linux, Windows, SQL, and generic CLI simulation**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-16T21:12:18Z
- **Completed:** 2026-03-16T21:14:51Z
- **Tasks:** 2
- **Files modified:** 1

## Accomplishments
- Created `app/src/utils/cliStateMachine.js` as a zero-dependency pure ES module
- Implemented all 5 domain schemas (cisco_ios, linux, windows, sql, generic) with correct prompt functions
- Cisco IOS: exec/config/config-if modes with static transitions (conf t, exit, end) and dynamic regex transition for `interface <name>`
- evaluateCommand() 4-step pipeline with correct step ordering, case-insensitive command_outputs lookup, and domain-appropriate error messages
- getPrompt() with domain fallback to generic and mode fallback to defaultMode

## Task Commits

Each task was committed atomically:

1. **Task 1+2: Create cliStateMachine.js with DOMAIN_SCHEMAS, getPrompt(), evaluateCommand()** - `ac38571` (feat)
2. **Auto-fix: Preserve interface name case in dynamic transitions** - `dc0d290` (fix)

**Plan metadata:** _(to be committed as docs commit)_

## Files Created/Modified
- `app/src/utils/cliStateMachine.js` - CLI state machine: DOMAIN_SCHEMAS, evaluateCommand(), getPrompt()

## Decisions Made
- Logic isolated in pure JS utility module (no Vue dependency) so Author Tool live preview can import the same module without pulling in Vue
- Dynamic transitions match against original trimmed cmd rather than normalized lowercase, preserving interface name casing (e.g. `Fa0/0` stays `Fa0/0`)
- errorMsg can be either a string (cisco_ios, sql, generic) or a function receiving cmd.trim() (linux, windows) for domain-specific error messages

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed interface name losing case in dynamic transitions**
- **Found during:** Task 2 verification (evaluateCommand)
- **Issue:** Dynamic transition matched `normalized` (lowercased cmd) against the regex pattern, causing `match[1]` to return lowercase interface names (`fa0/0` instead of `Fa0/0`)
- **Fix:** Match dynamic transitions against `cmd.trim()` (original case) instead of `normalized`, since the pattern already includes `/i` flag for case-insensitive trigger matching
- **Files modified:** app/src/utils/cliStateMachine.js
- **Verification:** `evaluateCommand('interface Fa0/0', 'cisco_ios', 'config', {}, {})` returns `nextContext.interface === 'Fa0/0'`
- **Committed in:** `dc0d290`

---

**Total deviations:** 1 auto-fixed (Rule 1 - bug fix)
**Impact on plan:** Required for correctness — interface names must preserve case for display in terminal prompt.

## Issues Encountered
None beyond the deviation above.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- `app/src/utils/cliStateMachine.js` ready for import in Wave 2 (01-02-PLAN.md)
- PbqCli.vue will import: `import { DOMAIN_SCHEMAS, evaluateCommand, getPrompt } from '../utils/cliStateMachine.js'`
- No blockers.

---
*Phase: 01-cli-state-machine*
*Completed: 2026-03-16*

## Self-Check: PASSED

- FOUND: app/src/utils/cliStateMachine.js
- FOUND: .planning/phases/01-cli-state-machine/01-01-SUMMARY.md
- FOUND commit: ac38571 (feat: DOMAIN_SCHEMAS + getPrompt + evaluateCommand)
- FOUND commit: dc0d290 (fix: interface name case preservation)
- FOUND commit: e079380 (docs: SUMMARY + STATE + ROADMAP + REQUIREMENTS)
