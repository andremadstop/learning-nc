---
phase: 154-pass-definition
plan: 05
subsystem: frontend
tags: [vue, options-api, certification, pass-status, instructor-ui, student-ui, vitest, i18n]

# Dependency graph
requires:
  - phase: 154-04
    provides: CourseService.js (updateCertConfig + getPassStatus) + cert/pass i18n keys
  - phase: 154-02
    provides: cert_* columns + snake_case jsonSerialize on Course entity
provides:
  - "CourseTabVerwaltung.vue — instructor cert-config block (enable toggle, threshold 1-100, pool multiselect, validity days) wired to updateCertConfig()"
  - "CourseSummary.vue — student Zeugnisstatus card sourced from getPassStatus(); computed zeugnisVisible/certApplicable/hasPassed/passedAtFormatted"
  - "CourseDetail.vue — :course-pools binding feeding pool list into the cert-config multiselect"
  - "9 Vitest state-assertion tests for the Zeugnisstatus computed properties"
affects: [155]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Cert fields synced from snake_case `course` prop via existing watch.course(immediate) — not camelCase created()"
    - "coursePools prop threaded CourseDetail → CourseTabVerwaltung; checkbox pushes integer pool_id"
    - "Zeugnisstatus exposed as computeds so Vitest createInstance() harness asserts state without DOM mount"

key-files:
  created: []
  modified:
    - app/src/components/CourseTabVerwaltung.vue
    - app/src/components/CourseSummary.vue
    - app/src/components/CourseDetail.vue
    - app/tests/unit/CourseSummary.test.js

key-decisions:
  - "Pass-status rendered as a NEW widget in CourseSummary.vue (lines 64-81 are the functional snapshot/swarm card, not a placeholder to replace)"
  - "Cert config synced from the snake_case course prop (cert_enabled, cert_pass_percent, cert_required_pool_ids, cert_validity_days) — the plan's camelCase created() would never have synced"
  - "Pool checkboxes emit integer pool_id (per 154-04 gotcha: course['pools'][n]['id'] is the mapping row id, pool_id is the real id)"
  - "Options API enforced throughout — no ref/setup/onMounted in either component (113-component project convention)"

requirements-completed: [PASS-01, PASS-02, PASS-03, PASS-04, PASS-06]

# Metrics
duration: ~25min
completed: 2026-06-26
---

# Phase 154 Plan 05: Vue Cert-Config & Zeugnisstatus UI Summary

Instructor cert-config block and student pass-status card wired to the 154-04 API using the project's Options API convention; Phase 154 pass-definition stack verified end-to-end on relay.

## What Was Built

The visible layer of Phase 154 — the two Vue surfaces that connect the tested backend (migration + service + controller) to actual users:

- **CourseTabVerwaltung.vue** (instructor): a "Zertifizierung" section in the Verwaltung tab with an enable toggle, a min-score input (1-100), a required-pools multiselect, and a validity-days input (0 = unbegrenzt). Save calls `updateCertConfig()`; values round-trip from the server response.
- **CourseSummary.vue** (student): a Zeugnisstatus card sourced from `getPassStatus()`, showing **Bestanden** (with pass date) or **Noch nicht bestanden** (with current score vs threshold), and **Kein Zertifikat für diesen Kurs** when the course does not certify.
- **CourseDetail.vue**: threads the course pool list into the cert-config multiselect via a new `:course-pools` binding.
- **CourseSummary.test.js**: 9 new Vitest tests asserting the four Zeugnisstatus computed properties against the `createInstance()` harness (state-assertion, no DOM mount).

## Tasks Completed

| Task | Name | Commit | Files |
| ---- | ---- | ------ | ----- |
| 1 | Cert-config block in CourseTabVerwaltung.vue (Options API) | 6dfb7f0 | CourseTabVerwaltung.vue, CourseDetail.vue |
| 2 | Zeugnisstatus card in CourseSummary.vue (Options API + computeds) | f9f2b9d | CourseSummary.vue |
| 3 | Vitest tests for Zeugnisstatus computed properties | c712685 | CourseSummary.test.js |
| 4 | Human-verify checkpoint — Phase 154 end-to-end on relay | (verification) | — |

## Verification

- **Vue build**: `npm run build` exits 0; JS deployed to relay via `deploy-prod.sh --js-only`.
- **ESLint**: 0 errors on both `.vue` files.
- **Vitest**: suite GREEN including the 9 new Zeugnisstatus computed tests.
- **Options API**: no `ref(`/`setup`/`onMounted` in either component (grep-verified by task `<verify>` blocks).
- **Human verify (Task 4) — approved**: all 15 checks pass on relay (https://devcloud.andrestiebitz.de).
  - Instructor flow (PASS-01..04): cert section visible; enable reveals sub-fields; threshold/pools/validity persist on save+reload; disable hides sub-fields.
  - Student flow (PASS-06): Zeugnisstatus card shows correct Bestanden/Noch nicht bestanden state per student; FSRS readiness not used as a pass indicator.
  - Audit idempotency (PASS-07): `course.passed` audit count = 1 after two CourseSummary reloads for a qualifying student.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Cert fields synced from snake_case course prop instead of plan's camelCase created()**
- **Found during:** Task 1
- **Issue:** The plan's `created()` hook read `this.course.certEnabled` (camelCase), but the Course entity's `jsonSerialize` emits **snake_case** cert keys (locked 154-02 decision: `cert_enabled`, `cert_pass_percent`, `cert_required_pool_ids`, `cert_validity_days`). The camelCase reads would always be `undefined`, so the config block would never reflect saved values.
- **Fix:** Synced from the snake_case keys via the component's existing `watch.course` (immediate) handler instead of a fresh `created()`.
- **Files modified:** CourseTabVerwaltung.vue
- **Commit:** 6dfb7f0

**2. [Rule 3 - Blocking] Added coursePools prop + CourseDetail wiring (outside plan's files_modified)**
- **Found during:** Task 1
- **Issue:** The plan assumed the pool list was already available in CourseTabVerwaltung; it was not. The multiselect had no option source.
- **Fix:** Added a `coursePools` prop to CourseTabVerwaltung and wired `:course-pools` in CourseDetail.vue. Pool checkboxes push the integer `pool_id` (per the 154-04 gotcha that `course['pools'][n]['id']` is the mapping-row id, not the real pool id).
- **Files modified:** CourseTabVerwaltung.vue, CourseDetail.vue
- **Commit:** 6dfb7f0

**3. [Plan correction] Pass-status rendered as a NEW widget, not a replacement of lines 64-81**
- **Found during:** Task 2
- **Issue:** The plan said to replace CourseSummary.vue lines 64-81 as a "placeholder"; those lines are the functional snapshot/swarm card, not a placeholder.
- **Fix:** Added the Zeugnisstatus card as a new widget rather than removing working UI.
- **Files modified:** CourseSummary.vue
- **Commit:** f9f2b9d

**4. [Rule 3 - Blocking] Registered the 4 new computeds in the test harness defineProperties block**
- **Found during:** Task 3
- **Issue:** `createInstance()` hardcodes the set of computed getters it exposes; the 4 new computeds were invisible to it.
- **Fix:** Added the new computeds to the harness's `defineProperties` block so the state-assertion tests can read them.
- **Files modified:** CourseSummary.test.js
- **Commit:** c712685

## Authentication Gates

None — the human-verify checkpoint was a visual/functional verification on an already-authenticated relay instance, not an auth gate.

## Requirements Completed

PASS-01, PASS-02, PASS-03, PASS-04, PASS-06 — instructor-facing cert config and student-facing pass status are now end-to-end functional. (PASS-05 and PASS-07 were already Complete from 154-03.) This closes all 7 PASS requirements and Phase 154.

## Self-Check: PASSED
