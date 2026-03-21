---
phase: 17-gemini-backend-security
plan: 02
subsystem: api
tags: [php, nextcloud, gemini, virtu-prof, rate-limit, ai]

# Dependency graph
requires:
  - phase: 17-01
    provides: "GeminiService with 5-layer security (input sanitizer, context isolation, output validation, rate limit, audit log)"
provides:
  - "POST /api/virtu-prof/chat HTTP endpoint in VirtuProfController"
  - "GeminiService injected into VirtuProfController via NC DI"
  - "ai_enabled admin guard (503 when disabled)"
  - "invalid_input guard returning HTTP 400"
  - "Route virtu_prof#chat registered in routes.php"
affects: [18-frontend-chat, virtu-prof-vue, frontend-api-calls]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "NC DI auto-wires constructor parameters by type — no Application.php changes needed"
    - "UserRateLimit attribute as backup burst guard; primary rate limiting in GeminiService"
    - "Admin feature flag pattern: getAppValue('learning', 'ai_enabled', 'no') !== 'yes' → 503"
    - "Client error mapped to HTTP 400 via reason field inspection"

key-files:
  created: []
  modified:
    - "app/lib/Controller/VirtuProfController.php"
    - "app/appinfo/routes.php"

key-decisions:
  - "ai_enabled guard runs before GeminiService.chat() — avoids unnecessary rate limit counter increments"
  - "invalid_input returns HTTP 400 (client error); all other GeminiService outcomes (fallback, rate_limit, api_error, output_blocked) return HTTP 200 with fallback flag for frontend"
  - "UserRateLimit(15, 60) on chat() as secondary burst guard; GeminiService has primary per-user 10/min + 100/day + global 8/min limits"

patterns-established:
  - "GeminiService fallback pattern: HTTP 200 always unless auth/guard/input error — frontend handles fallback:true via FAQ matcher"

requirements-completed: [GEM-01, GEM-02, GEM-03, GEM-04, SEC-01, SEC-02, SEC-03, SEC-04, SEC-05]

# Metrics
duration: 8min
completed: 2026-03-21
---

# Phase 17 Plan 02: VirtuProf Chat Endpoint Summary

**HTTP adapter wiring GeminiService to frontend: POST /api/virtu-prof/chat with ai_enabled guard, input validation (400), and graceful fallback (200+fallback:true) for all API failures**

## Performance

- **Duration:** 8 min
- **Started:** 2026-03-21T13:26:39Z
- **Completed:** 2026-03-21T13:34:00Z
- **Tasks:** 2/2
- **Files modified:** 2

## Accomplishments
- Extended VirtuProfController with GeminiService constructor injection (NC DI auto-wires)
- Added chat() action with @NoAdminRequired, UserRateLimit(15/min), ai_enabled guard, and graceful fallback
- Registered POST /api/virtu-prof/chat in routes.php
- Smoke tests confirmed: HTTP 503 when ai_enabled=no, HTTP 400 on >500 chars, HTTP 200 + fallback:true when no API key

## Task Commits

Each task was committed atomically:

1. **Task 1: Extend VirtuProfController with GeminiService injection and chat()** - `49813e5` (feat)
2. **Task 2: Register chat route + deploy + smoke test** - `403feca` (feat)

**Plan metadata:** (docs commit follows)

## Files Created/Modified
- `app/lib/Controller/VirtuProfController.php` - Added GeminiService import, constructor param, chat() action
- `app/appinfo/routes.php` - Added virtuProf#chat route at /api/virtu-prof/chat

## Decisions Made
- ai_enabled guard runs before GeminiService.chat() to avoid rate limit counter increments when feature is disabled
- HTTP 400 only for invalid_input (client error); all GeminiService fallback outcomes return HTTP 200 with fallback:true so frontend FAQ matcher can handle them
- UserRateLimit(15, 60) acts as secondary burst guard at the HTTP layer; GeminiService holds the primary rate limits

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
- `docker cp` with full app directory failed due to node_modules size — used targeted `docker cp` per-file instead. This is a known deploy pattern, not a bug.

## User Setup Required
None - no external service configuration required for this plan. Admin must configure `gemini_api_key` via the Settings UI (built in plan 17-01) and set `ai_enabled=yes` to activate the endpoint.

## Next Phase Readiness
- POST /api/virtu-prof/chat is live and reachable
- Returns HTTP 200 with fallback:true/reason:api_error until a valid Gemini API key is configured
- Frontend (Phase 18) can integrate by: checking fallback flag and triggering FAQ matcher when true
- Admin must set ai_enabled=yes and gemini_api_key before live Gemini responses work

---
*Phase: 17-gemini-backend-security*
*Completed: 2026-03-21*

## Self-Check: PASSED
- FOUND: app/lib/Controller/VirtuProfController.php
- FOUND: app/appinfo/routes.php
- FOUND: .planning/phases/17-gemini-backend-security/17-02-SUMMARY.md
- FOUND commit 49813e5 (Task 1)
- FOUND commit 403feca (Task 2)
