---
phase: 62-hint-system
plan: 01
subsystem: ai
tags: [gemini, virtuprof, hint-system, prompt-engineering, vue]

requires:
  - phase: 61-kontext-mapping
    provides: questionContext sent from frontend to VirtuProf chat API
provides:
  - Graduated 3-level hint system for VirtuProf (directional nudge, narrowing, full explanation)
  - buildHintAddendum() prompt engineering method in GeminiService
  - Hint keyword detection (Tipp/Hint/Hilfe) in VirtuProf.vue
  - Per-question hint level tracking with auto-reset on question change
affects: [63-exam-sperre]

tech-stack:
  added: []
  patterns: [graduated-prompt-engineering, keyword-detection-with-context-guard]

key-files:
  created: []
  modified:
    - app/lib/Controller/VirtuProfController.php
    - app/lib/Service/GeminiService.php
    - app/src/components/VirtuProf.vue

key-decisions:
  - "Hint keywords match full message or start/end position, not substring — prevents false triggers on normal sentences containing 'help'"
  - "hintLevel only sent when questionContext is present — no hints without a question to hint about"
  - "Hint addendum appended AFTER questionContext addendum so Gemini has both question data and hint instruction"

patterns-established:
  - "Graduated prompt engineering: use numbered hint levels in system prompt to control answer verbosity"
  - "Keyword detection with context guard: isHintRequest() + questionContext check before activating special behavior"

requirements-completed: [HINT-01, HINT-02, HINT-03]

duration: 4min
completed: 2026-03-24
---

# Phase 62 Plan 01: Hint-System Summary

**Graduated 3-level hint system for VirtuProf: keyword detection (Tipp/Hint/Hilfe) triggers progressive hints via Gemini prompt engineering, with per-question level tracking and auto-reset**

## Performance

- **Duration:** 4 min
- **Started:** 2026-03-24T13:08:18Z
- **Completed:** 2026-03-24T13:12:47Z
- **Tasks:** 2
- **Files modified:** 3

## Accomplishments
- Backend accepts hintLevel parameter, clamps to 1-3, passes through to GeminiService which generates graduated prompt instructions
- Frontend detects hint keywords ("Tipp", "Hint", "Hilfe" and variants), tracks hint level per question, resets on question change
- Normal chat flow completely unaffected — hintLevel only sent when keyword matches AND questionContext exists

## Task Commits

Each task was committed atomically:

1. **Task 1: Backend — Accept hintLevel and generate graduated hint prompts** - `1eaf9ee` (feat)
2. **Task 2: Frontend — Track hint level per question, detect keywords, send with chat** - `deec252` (feat)

## Files Created/Modified
- `app/lib/Controller/VirtuProfController.php` - Added hintLevel parameter to chat(), validation/clamping, pass-through to GeminiService
- `app/lib/Service/GeminiService.php` - Added hintLevel to chat()/buildSystemPrompt(), new buildHintAddendum() with 3-level graduated prompts
- `app/src/components/VirtuProf.vue` - Added hintLevel/lastHintQuestionId data, isHintRequest() keyword detection, hint increment in handleChatSend(), reset in updateContext()

## Decisions Made
- Hint keywords use position-aware matching (full message, start, or end) rather than simple substring to avoid false triggers
- hintLevel is only activated when both a hint keyword is detected AND a questionContext exists
- Level 3 gives full explanation including correct answer — no confirmation step (plan specified direct reveal at level 3)

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Hint system complete and ready for Phase 63 (Exam-Sperre + Fehler-Report)
- Phase 63 can build on the questionContext infrastructure from Phase 61 and this hint system

---
*Phase: 62-hint-system*
*Completed: 2026-03-24*
