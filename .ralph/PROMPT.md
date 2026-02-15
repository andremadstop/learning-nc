# Ralph Development Instructions — Learning-NC

## Project
Learning-NC: Native Nextcloud Spaced Repetition App (Leitner System)
PHP 8.1+ backend (Nextcloud App Framework) + Vue 2.7 frontend + PostgreSQL 16

## Current Objectives
Work through the fix_plan.md tasks in priority order. Focus on App Store readiness:
smoke-tests, version bump, git cleanup, documentation.

## Key Principles
1. **ONE task per loop** — complete it fully before moving on
2. **Search before assuming** — read existing code before modifying
3. **Code lives in `app/`** — all source code is under the `app/` subdirectory
4. **Deploy after changes** — use the deploy workflow in CLAUDE.md to push changes to learning-dev
5. **Test after every change** — curl the API to verify nothing is broken
6. **Update fix_plan.md** — mark tasks [x] when complete, add notes

## Architecture Notes
- Backend: PHP Controllers → Services → Db Mappers (Nextcloud QBMapper ORM)
- Frontend: Vue 2.7 SFCs, @nextcloud/vue component library, Webpack 5
- 51 API endpoints (see CLAUDE.md for full list)
- 13 DB tables, all owned by `oc_admin`
- Dev server: learning-dev (.65), Docker container `learning-app`

## Testing Guidelines
- Limit testing to 20% of effort per loop
- Use curl for API smoke tests: `ssh learning-dev 'curl -s -u admin:admin http://localhost:8080/apps/learning/api/pools'`
- For frontend: build + deploy, then verify in browser description (or curl the HTML)
- No automated test framework set up — manual API testing via curl

## Execution Guidelines
- Read CLAUDE.md for deploy workflow before making changes
- PHP changes: edit locally → scp → docker cp → opcache_reset
- Vue changes: edit locally → ssh npm run build → docker cp js bundle
- Always check `docker exec learning-app php -r 'opcache_reset();'` after PHP changes

## Status Reporting
At the end of each loop, output:

```
---RALPH_STATUS---
STATUS: IN_PROGRESS | COMPLETE | BLOCKED
TASKS_COMPLETED_THIS_LOOP: <number>
FILES_MODIFIED: <number>
TESTS_STATUS: PASSING | FAILING | NOT_RUN
WORK_TYPE: IMPLEMENTATION | TESTING | DOCUMENTATION | REFACTORING
EXIT_SIGNAL: false | true
RECOMMENDATION: <one line summary>
---END_RALPH_STATUS---
```

## File Structure
- `CLAUDE.md` — Project context, deploy workflow, architecture
- `.ralph/PROMPT.md` — This file (development instructions)
- `.ralph/AGENT.md` — Build/test/run commands
- `.ralph/fix_plan.md` — Prioritized task list
- `.ralph/specs/` — Specifications (if needed)
- `.ralph/logs/` — Loop execution logs
