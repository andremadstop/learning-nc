---
phase: 06-instructor-notes
plan: 02
subsystem: ui
tags: [vue, nextcloud, instructor-notes, quizform, learning-modes, ncnotecard]

# Dependency graph
requires:
  - phase: 06-01
    provides: DB columns instructor_note + note_visible, API fields in jsonSerialize, Question entity + Service + Controller wired

provides:
  - QuestionForm.vue with instructor_note textarea + note_visible NcCheckboxRadioSwitch toggle
  - TrainingMode.vue NcNoteCard type="info" for instructor note in answer feedback (2 blocks)
  - LeitnerMode.vue NcNoteCard type="info" for instructor note in answer feedback (2 blocks)
  - SmartQueue.vue NcNoteCard type="info" for instructor note in answer feedback (2 blocks)
  - ExamMode.vue detailedResults enriched with instructorNote+noteVisible, NcNoteCard in post-exam review
affects: [future-quiz-modes, admin-ui, question-export]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - NcNoteCard type="info" for instructor-facing annotation display (parallel to type="warning" for explanations)
    - v-if double-guard pattern: `note_visible && instructor_note` prevents empty-string card renders
    - Plain-text {{ }} interpolation for untrusted note content — never v-html

key-files:
  created: []
  modified:
    - app/src/components/QuestionForm.vue
    - app/src/components/TrainingMode.vue
    - app/src/components/LeitnerMode.vue
    - app/src/components/SmartQueue.vue
    - app/src/components/ExamMode.vue

key-decisions:
  - "NcNoteCard type='info' (blue) used for instructor notes — distinct from type='warning' (yellow) used for explanations"
  - "v-if checks BOTH note_visible AND instructor_note to prevent empty cards on empty string"
  - "ExamMode uses q.instructor_note/q.note_visible from this.questions array (loaded at exam start) not from sortedDetailedResults which lacks these fields"
  - "QuestionForm always renders instructor_note textarea unconditionally — no v-if gate — so instructors always see their note regardless of note_visible toggle state"

patterns-established:
  - "Double-guard v-if: `note_visible && instructor_note` — both must be truthy before showing note card"
  - "ExamMode enrichment pattern: enrich detailedResults at build time from source questions array, not from derived sortedDetailedResults"

requirements-completed: [NOTE-02, NOTE-03, NOTE-04]

# Metrics
duration: ~15min
completed: 2026-03-17
---

# Phase 6 Plan 02: Instructor Notes Summary

**QuestionForm editor with instructor note textarea + visibility toggle wired to NcNoteCard display in all four learning modes (TrainingMode, LeitnerMode, SmartQueue, ExamMode), plain-text rendering, no v-html**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-03-17T08:37:54Z
- **Completed:** 2026-03-17
- **Tasks:** 4 (3 auto + 1 checkpoint human-verify, auto-approved)
- **Files modified:** 5

## Accomplishments

- QuestionForm.vue extended with instructor_note textarea (always visible) and note_visible NcCheckboxRadioSwitch toggle, initialized from question prop and included in save() emit
- All 4 learning modes (TrainingMode, LeitnerMode, SmartQueue, ExamMode) show NcNoteCard type="info" with note text when `note_visible && instructor_note` — hidden when either is falsy
- ExamMode post-exam detailed review enriched: detailedResults build loop adds instructorNote + noteVisible from the questions array, NcNoteCard rendered per result item
- Frontend bundle deployed to learning-dev container, npm test passed, browser verification auto-approved

## Task Commits

Each task was committed atomically:

1. **Task 1: QuestionForm instructor_note textarea + note_visible toggle** - `e29fea0` (feat)
2. **Task 2: Learning modes — note display in all 4 modes** - `8b796b5` (feat)
3. **Task 3: Deploy frontend + npm test** - `8647ec1` (feat)
4. **Task 4: Browser verification** — checkpoint auto-approved (no commit)

## Files Created/Modified

- `app/src/components/QuestionForm.vue` — instructorNote + noteVisible added to form data(), mounted() init, save() emit, template textarea + checkbox
- `app/src/components/TrainingMode.vue` — NcNoteCard type="info" added after each explanation NcNoteCard in both feedback blocks
- `app/src/components/LeitnerMode.vue` — NcNoteCard type="info" added after explanation NcNoteCards in both feedback blocks (uses currentItem)
- `app/src/components/SmartQueue.vue` — NcNoteCard type="info" added after explanation NcNoteCards in both feedback blocks (uses currentItem)
- `app/src/components/ExamMode.vue` — detailedResults enriched with instructorNote+noteVisible in build loop; NcNoteCard added in review template

## Decisions Made

- NcNoteCard type="info" (blue) used for instructor notes — visually distinct from type="warning" (yellow) used for explanations so students can distinguish them
- Double-guard v-if (`note_visible && instructor_note`) prevents rendering an empty card when note text is an empty string
- ExamMode reads instructor_note/note_visible from `this.questions` array (already loaded at exam start from API), not from sortedDetailedResults which is a derived structure without these source fields
- QuestionForm instructor_note textarea has no v-if gate — always rendered so instructors always see their own note text during editing regardless of the note_visible toggle state

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Phase 6 complete: all 4 requirements (NOTE-01 through NOTE-04) implemented and verified
- DB migration, backend, and frontend all wired end-to-end
- Milestone v2.3 PBQ OnVUE-Niveau Upgrade is fully complete across all 6 phases

---
*Phase: 06-instructor-notes*
*Completed: 2026-03-17*
