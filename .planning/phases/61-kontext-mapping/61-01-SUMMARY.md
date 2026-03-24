---
phase: 61-kontext-mapping
plan: 01
subsystem: api
tags: [gemini, virtuprof, system-prompt, question-context, php]

requires:
  - phase: none
    provides: existing VirtuProf chat backend (VirtuProfController + GeminiService)
provides:
  - questionContext parameter on VirtuProf chat endpoint
  - question-aware system prompt injection in GeminiService
affects: [62-hint-system, 63-exam-rep]

tech-stack:
  added: []
  patterns: [question context addendum pattern in system prompt, sanitized optional array parameter]

key-files:
  created: []
  modified:
    - app/lib/Controller/VirtuProfController.php
    - app/lib/Service/GeminiService.php

key-decisions:
  - "questionContext as separate addendum from RAG context (RAG = pool-level stats, questionContext = exact question on screen)"
  - "Correct answer omitted when correctAnswerIndex is null (exam mode safety)"

patterns-established:
  - "Question context addendum: separate from RAG addendum, appended last in system prompt parts array"
  - "Sanitization pattern: strip_tags + mb_substr truncation on all user-provided strings before LLM injection"

requirements-completed: [CTX-01, CTX-02]

duration: 6min
completed: 2026-03-24
---

# Phase 61 Plan 01: Kontext-Mapping Backend Summary

**Question context injection into VirtuProf chat backend -- controller accepts questionContext array, GeminiService builds question-aware system prompt with answer labels and exam-mode safety**

## Performance

- **Duration:** 6 min
- **Started:** 2026-03-24T11:59:10Z
- **Completed:** 2026-03-24T12:05:00Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments
- VirtuProfController::chat() accepts optional questionContext array with sanitization (strip_tags, mb_substr, array limits)
- GeminiService builds question-aware system prompt addendum with question text, A-Z labeled answers, correct answer, and explanation
- Exam mode safety: correct answer omitted when correctAnswerIndex is null
- Zero impact on existing calls without questionContext (null default, empty addendum)

## Task Commits

Each task was committed atomically:

1. **Task 1: Add questionContext parameter to VirtuProfController::chat()** - `e6c732e` (feat)
2. **Task 2: Inject question context into GeminiService system prompt** - `b8d1987` (feat)

## Files Created/Modified
- `app/lib/Controller/VirtuProfController.php` - Added questionContext parameter, sanitization logic, pass-through to GeminiService
- `app/lib/Service/GeminiService.php` - Added buildQuestionContextAddendum() method, updated chat() and buildSystemPrompt() signatures

## Decisions Made
- questionContext kept separate from RAG context (RAG = pool-level statistics and sample questions, questionContext = exact question currently on screen)
- Correct answer is only included when correctAnswerIndex is explicitly provided and not null, ensuring exam mode does not leak answers

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Backend ready for frontend integration (Plan 61-02: VirtuProf.vue sends questionContext in payload)
- PHPStan level 5 passes on both modified files
- Backward compatible: existing frontend calls without questionContext continue working

---
*Phase: 61-kontext-mapping*
*Completed: 2026-03-24*
