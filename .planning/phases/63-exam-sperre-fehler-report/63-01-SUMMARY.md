---
phase: 63-exam-sperre-fehler-report
plan: 01
subsystem: ui
tags: [virtuprof, exam-mode, error-reporting, tickets, vue]

requires:
  - phase: none
    provides: existing VirtuProf chat + SupportTicketService
provides:
  - VirtuProf exam-mode lock via root event bus
  - Fehler melden button with automatic questionId
  - Ticket routing with course_content category for question errors
affects: [virtuprof, exam-mode, support-tickets]

tech-stack:
  added: []
  patterns: [root-event-bus for cross-component state, category-based ticket routing]

key-files:
  created: []
  modified:
    - app/src/components/ExamMode.vue
    - app/src/components/VirtuProf.vue
    - app/src/components/VirtuProfBubble.vue
    - app/lib/Controller/VirtuProfController.php
    - app/l10n/de.json

key-decisions:
  - "Chat history remains visible during exam lock, only input/suggestions hidden"
  - "Report button uses existing ticket-intent pipeline via structured message"
  - "Question error tickets routed as course_content category for instructor visibility"

patterns-established:
  - "virtuprof:exam-mode event pattern for cross-component exam state"

requirements-completed: [EXAM-01, EXAM-02, REP-01, REP-02]

duration: 13min
completed: 2026-03-24
---

# Phase 63 Plan 01: Exam-Sperre + Fehler-Report Summary

**VirtuProf chat lock during exam mode via event bus + "Fehler melden" button with automatic questionId and instructor routing**

## Performance

- **Duration:** 13 min
- **Started:** 2026-03-24T15:18:07Z
- **Completed:** 2026-03-24T15:31:18Z
- **Tasks:** 2
- **Files modified:** 5

## Accomplishments
- VirtuProf chat input and suggestions automatically disabled when ExamMode is active
- Lock icon with "Not available during exam" notice shown instead of chat input
- "Fehler melden" button appears when question context is active, sends structured report with questionId
- Backend passes questionId to SupportTicketService, routes question errors to instructor via course_content category

## Task Commits

Each task was committed atomically:

1. **Task 1: Exam-Sperre** - `63c5781` (feat)
2. **Task 2: Fehler-Report** - `d85443d` (feat)

## Files Created/Modified
- `app/src/components/ExamMode.vue` - Emits virtuprof:exam-mode true/false on exam lifecycle events
- `app/src/components/VirtuProf.vue` - Tracks isExamMode, passes examBlocked/hasQuestionContext props, handleReportError method
- `app/src/components/VirtuProfBubble.vue` - Exam blocked notice, report error button, new props + styles
- `app/lib/Controller/VirtuProfController.php` - questionId passthrough to handleTicketIntent, category routing
- `app/l10n/de.json` - German translations for new UI strings

## Decisions Made
- Chat history remains visible during exam lock (user might have pre-exam messages) -- only input and suggestions are hidden
- Report button reuses existing ticket-intent pipeline by sending a structured "Fehler melden: Frage #ID" message that matches isTicketIntent()
- Question error tickets use course_content category so they route to the course instructor instead of admin

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Exam lock and error reporting are ready for manual verification
- Deploy to learning-dev needed for browser testing

---
*Phase: 63-exam-sperre-fehler-report*
*Completed: 2026-03-24*
