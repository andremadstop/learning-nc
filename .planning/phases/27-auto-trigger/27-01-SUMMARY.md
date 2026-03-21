---
phase: 27
plan: 01
subsystem: auto-trigger
tags: [auto-trigger, background-job, virtu-prof, note-generator, trig01, trig02, trig03, trig04]
dependency_graph:
  requires:
    - 24-01-SUMMARY.md  # NoteGeneratorService
    - 22-01-SUMMARY.md  # LernprofilService
  provides:
    - WeeklyLernplanJob (TRIG-03)
    - exam auto-note trigger (TRIG-01)
    - wrong-answer threshold trigger (TRIG-02)
    - manual summary button (TRIG-04)
  affects:
    - TrainingService.completeSession()
    - TrainingService.submitAnswer()
    - ExamMode.vue
    - TrainingMode.vue
    - VirtuProf.vue
tech_stack:
  added:
    - WeeklyLernplanJob (NC TimedJob, 7-day interval)
  patterns:
    - Best-effort async note generation (tryAutoGenerateExamNote)
    - DB count query for wrong-answer threshold detection
    - VirtuProf action type routing ('generate-note-for-context')
key_files:
  created:
    - app/lib/BackgroundJob/WeeklyLernplanJob.php
  modified:
    - app/lib/AppInfo/Application.php
    - app/lib/Service/TrainingService.php
    - app/src/components/ExamMode.vue
    - app/src/components/TrainingMode.vue
    - app/src/components/VirtuProf.vue
    - app/src/utils/virtuprof-scripts.js
    - app/phpstan-baseline.neon
decisions:
  - Hook TRIG-01 in TrainingService.completeSession() not TrainingController — closer to data, single responsibility
  - tryAutoGenerateExamNote() swallows errors — exam completion must never fail because of optional note gen
  - TRIG-02 count query uses 30-day window to catch cumulative weak patterns across sessions
  - WeeklyLernplanJob generates note for weakest pool only (top 1) to control Gemini costs
  - LernplanService PHPStan false positive added to baseline (comparison 0 > 0 on typed DB integer)
metrics:
  duration: 19min
  completed: 2026-03-21
  tasks: 4
  files: 7
---

# Phase 27 Plan 01: Auto-Trigger Summary

Bot acts proactively after exam failure, repeated wrong answers, and on a weekly schedule — without user intervention.

## What Was Built

### TRIG-01: Exam Auto-Note (<70% Score)

`TrainingService::completeSession()` now calls `tryAutoGenerateExamNote()` after finalizing any exam session where `score_percentage < 70`. The method delegates to `NoteGeneratorService::generateSummary()` and swallows all exceptions so exam completion is never blocked. The response includes `auto_note: { generated: bool, path: string|null }`.

`ExamMode.vue` reads `cr.data.auto_note` and emits `virtuprof:trigger('exam-note-generated', { notePath })` when `generated === true`, showing the VirtuProf hint after a 2.5s delay (higher priority than `exam-low-score`).

### TRIG-02: Wrong-Answer Threshold (5+ Wrong on Same Pool)

`TrainingService::submitAnswer()` now calls `countRecentWrongAnswersOnPool()` after every incorrect answer. This DB query counts wrong answers for the user on the same pool in the last 30 days. The count is returned as `wrong_answers_on_pool` in the response.

`TrainingMode.vue` checks `response.data.wrong_answers_on_pool >= 5` after each wrong answer and emits `virtuprof:trigger('suggest-summary', { poolId })`. VirtuProf shows a step with two action buttons: "Zusammenfassung erstellen" (calls `generate-note-for-context` action → POST `/api/notes/generate`) and "Nicht jetzt".

### TRIG-03: Weekly Background Job

`WeeklyLernplanJob` extends `TimedJob` with a 7-day interval. On each run it:
1. Queries `learning_sessions` for active users (at least one session in the last 60 days, batch max 200)
2. For each user, calls `LernprofilService::getWeakestTopics(userId, null, 1)`
3. If the weakest pool has `error_rate >= 20%`, calls `NoteGeneratorService::generateSummary()`

The job is registered in `Application::boot()` alongside `ConsistencyCheckJob` and `NotificationJob`.

### TRIG-04: Manual "Zusammenfassung erstellen" Button

`TrainingMode.vue` results screen now shows a "Create Summary Note" secondary button. On click it calls POST `/api/notes/generate` with `pool_id` and `course_id`. On success it shows the NC file path; on failure it shows the error message. State resets on `restartTraining()`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Pre-existing Bug] LernplanService PHPStan false positive**
- **Found during:** Task 1 commit
- **Issue:** PHPStan reported `Comparison operation ">" between 0 and 0 is always false` on line 424 of LernplanService (from Phase 25). PHPStan inferred the DB `total_q` value as literal `0` due to the array initialization.
- **Fix:** Added entry to `phpstan-baseline.neon` so the false positive is suppressed.
- **Files modified:** `app/phpstan-baseline.neon`

None of the task-specific code had deviations. Plan executed as specified.

## Self-Check: PASSED

All 7 modified/created files exist on disk. All 3 task commits verified in git history:
- `80f5da0` WeeklyLernplanJob + Application.php + phpstan baseline
- `821d57f` TrainingService TRIG-01 + TRIG-02
- `40feb9b` Frontend: ExamMode.vue, TrainingMode.vue, VirtuProf.vue, virtuprof-scripts.js
